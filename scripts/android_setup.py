#!/usr/bin/env python3
"""Install checksum-verified Android tools into ignored local state. No credentials required."""
from pathlib import Path
import hashlib,json,os,shutil,subprocess,tarfile,urllib.parse,urllib.request,zipfile
ROOT=Path(__file__).resolve().parents[1]
RUNTIME=ROOT/'.runtime'
TOOLS=RUNTIME/'android-tools'
SDK=RUNTIME/'android-sdk'
PINS=json.loads((ROOT/'android/toolchain.json').read_text())
def download(url,path,algorithm,expected):
    if not path.exists() or hashlib.new(algorithm,path.read_bytes()).hexdigest()!=expected:
        with urllib.request.urlopen(url,timeout=60)as source,path.open('wb')as target:
            shutil.copyfileobj(source,target)
    if hashlib.new(algorithm,path.read_bytes()).hexdigest()!=expected:raise RuntimeError('Toolchain checksum validation failed.')
def prepare(emulator=False):
    TOOLS.mkdir(parents=True,exist_ok=True);SDK.mkdir(exist_ok=True)
    download(PINS['sdk_url'],TOOLS/'sdk.zip',PINS['sdk_algorithm'],PINS['sdk_checksum'])
    download('https://downloads.gradle.org/distributions/gradle-'+PINS['gradle_version']+'-bin.zip',TOOLS/'gradle.zip','sha256',PINS['gradle_sha256'])
    if not (SDK/'cmdline-tools/latest/bin/sdkmanager').exists():
        with zipfile.ZipFile(TOOLS/'sdk.zip')as z:z.extractall(SDK/'cmdline-tools')
        (SDK/'cmdline-tools/cmdline-tools').rename(SDK/'cmdline-tools/latest')
    gradle=TOOLS/('gradle-'+PINS['gradle_version'])
    if not gradle.exists():
        with zipfile.ZipFile(TOOLS/'gradle.zip')as z:z.extractall(TOOLS)
    for path in list((SDK/'cmdline-tools/latest/bin').iterdir())+[gradle/'bin/gradle']:path.chmod(0o755)
    download(PINS['jdk']['url'],TOOLS/'jdk.tar.gz','sha256',PINS['jdk']['sha256'])
    with tarfile.open(TOOLS/'jdk.tar.gz')as archive:
        name=archive.getmembers()[0].name.split('/')[0]
        if not (TOOLS/name/'bin/javac').exists():archive.extractall(TOOLS,filter='data')
    jdk=TOOLS/name;(TOOLS/'jdk-path.txt').write_text(str(jdk))
    user=RUNTIME/'android-user';user.mkdir(exist_ok=True)
    env=dict(os.environ,JAVA_HOME=str(jdk),ANDROID_USER_HOME=str(user),GRADLE_USER_HOME=str(RUNTIME/'gradle-cache'))
    env.pop('ANDROID_PREFS_ROOT',None)
    cache=Path(env['GRADLE_USER_HOME']);cache.mkdir(exist_ok=True)
    # Keep the managed system trust, including explicitly installed enterprise roots.
    trust=TOOLS/'cacerts'
    if not trust.exists():shutil.copyfile(jdk/'lib/security/cacerts',trust)
    for i,path in enumerate(sorted(Path('/usr/local/share/ca-certificates').glob('*.crt'))):
        subprocess.run([str(jdk/'bin/keytool'),'-importcert','-noprompt','-keystore',str(trust),'-storepass','changeit','-alias','ctai-host-'+str(i),'-file',str(path)],capture_output=True)
    props='systemProp.javax.net.ssl.trustStore='+str(trust)+'\nsystemProp.javax.net.ssl.trustStorePassword=changeit\n'
    proxy=urllib.parse.urlparse(os.getenv('HTTPS_PROXY',''))
    if proxy.hostname:
        for scheme in ['https','http']:props+='systemProp.'+scheme+'.proxyHost='+proxy.hostname+'\nsystemProp.'+scheme+'.proxyPort='+str(proxy.port or 80)+'\n'
    (cache/'gradle.properties').write_text(props)
    env['JAVA_OPTS']=env.get('JAVA_OPTS','')+' -Djavax.net.ssl.trustStore='+str(trust)+' -Djavax.net.ssl.trustStorePassword=changeit'
    args=[str(SDK/'cmdline-tools/latest/bin/sdkmanager'),'--sdk_root='+str(SDK)]
    if proxy.hostname:args+=['--proxy=http','--proxy_host='+proxy.hostname,'--proxy_port='+str(proxy.port or 80)]
    packages=['platforms;android-35','build-tools;35.0.0','platform-tools']
    if emulator:packages+=['emulator','system-images;android-28;google_apis;x86_64']
    # Reuse installed packages when building without network access. sdkmanager
    # otherwise refreshes remote manifests even when every required tool exists.
    markers={'platforms;android-35':'android.jar','build-tools;35.0.0':'apksigner','platform-tools':'adb','emulator':'emulator','system-images;android-28;google_apis;x86_64':'system.img'}
    missing=[p for p in packages if not all((SDK/Path(*p.split(';'))/name).is_file() for name in ['package.xml',markers[p]])]
    if missing:
        licenses=subprocess.run(args+['--licenses'],input='y\n'*100,text=True,env=env,capture_output=True)
        if licenses.returncode:raise RuntimeError('Android SDK license acceptance failed.')
        result=subprocess.run(args+missing,env=env,capture_output=True,text=True)
        if result.returncode:raise RuntimeError('Android SDK package installation failed: '+result.stderr[-1000:])
    (ROOT/'android/local.properties').write_text('sdk.dir='+str(SDK)+'\n')
    print('Android SDK, JDK and Gradle verified and prepared.')
    return env,gradle/'bin/gradle'
if __name__=='__main__':
    import argparse
    parser=argparse.ArgumentParser();parser.add_argument('--emulator',action='store_true');prepare(parser.parse_args().emulator)
