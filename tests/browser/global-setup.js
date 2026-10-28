// Reset only local development throttling between complete test runs; production guards stay active.
const {spawnSync}=require('node:child_process');
module.exports=()=>{
  const code='if(home_url()!=="http://localhost:8080"){throw new Exception("Browser fixtures require the local development site");}global $wpdb;if($wpdb->query("DELETE FROM ".CrystalTap\\DB::table("rate_limits"))===false){throw new Exception("Could not reset local test throttles");}';
  const result=spawnSync('docker',['compose','run','--rm','-T','cli','wp','eval',code],{encoding:'utf8'});
  if(result.status!==0)throw new Error('Could not prepare local browser test throttles. Verify the local development stack.');
};
