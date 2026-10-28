<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

/** Contact details belong to a conversation and follow its access/retention rules. */
final class Customer {
    public static function validate(array $input): array {
        foreach (['name','email','phone'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) { throw new Fault('Enter valid customer contact details.'); }
        }
        $name=trim(sanitize_text_field($input['name']??''));
        $email=trim($input['email']??'');$phone=trim(sanitize_text_field($input['phone']??''));
        if ($name==='' || mb_strlen($name)>190) { throw new Fault('Your name is required (up to 190 characters).',400,'customer_name_required'); }
        if ($email!=='' && (strlen($email)>190 || !is_email($email))) { throw new Fault('Enter a valid email address or leave it blank.'); }
        if (mb_strlen($phone)>60 || ($phone!=='' && (!preg_match('/[0-9]/',$phone) || !preg_match('/^[+0-9() .\-#extEXT]{3,60}$/u',$phone)))) { throw new Fault('Enter a valid phone number or leave it blank.'); }
        return ['name'=>$name,'email'=>$email,'phone'=>$phone];
    }
    public static function details(array $conversation): ?array {
        global $wpdb;
        $details=['name'=>$conversation['customer_name']??'','email'=>$conversation['customer_email']??'','phone'=>$conversation['customer_phone']??'','location'=>'','interest'=>'','water_source'=>''];
        $lead=$wpdb->get_row($wpdb->prepare('SELECT name,email,phone,location,interest,water_source FROM '.DB::table('leads').' WHERE conversation_id=%d',$conversation['id']),ARRAY_A);
        foreach ($lead??[] as $key=>$value) { if ($value!=='') { $details[$key]=$value; } }
        return $details['name']!=='' || $lead ? $details : null;
    }
}
