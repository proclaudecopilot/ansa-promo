<?php
/* 1.24.21: window.AnsaPromoEditor за статичния харнес на редактора (без WP): cfg = нормализираната чернова от мокъпа, регистърът, 📄 Страници.
   php tests/harness/editor-boot.php file:///…/harness/canvas.html → JSON */
require __DIR__.'/../wpstub.php';
use AnsaPromo\Config; use AnsaPromo\Copy; use AnsaPromo\Seed; use AnsaPromo\Editor;
list($cfg,$rep)=Seed::build(false); $cfg['page_id']=1; $cfg=Config::normalize($cfg);
$keys=[]; foreach(Copy::registry() as $g=>$ks){ foreach($ks as $k=>$v){ $keys[$k]=['g'=>$g,'d'=>$v,'v'=>Copy::placeholders($v)]; } }
$gate=[]; foreach(array_keys(Config::gate_image_slots()) as $k){ $gate[$k]='file://'.ANSA_PROMO_PATH.'assets/img/gate/'.$k.'.svg'; }
$cfg['copy']=(object)$cfg['copy']; $cfg['fit']=(object)$cfg['fit']; $cfg['campaigns']=(object)($cfg['campaigns']??[]); $cfg['copy_utm']=(object)[]; $cfg['gate']['images']=(object)$cfg['gate']['images']; $cfg['rewards']['icons']=(object)$cfg['rewards']['icons'];
foreach($cfg['pages'] as $pg=>$p){ $cfg['pages'][$pg]['images']=(object)$p['images']; }
$prods=[]; foreach($cfg['products'] as $p){ $prods[]=['key'=>$p['key'],'name'=>$p['name'],'enabled'=>true,'pid'=>0]; }
$canvas=$argv[1]??'';
echo json_encode(['ajax'=>'','nonce'=>'x','ver'=>ANSA_PROMO_VER,'page'=>$canvas,'page_url'=>'https://ansa.bg/sezonut-na-ansa/','admin'=>'#','products_tab'=>'#',
 'registry'=>['groups'=>Copy::groups(),'keys'=>$keys,'vars'=>Copy::vars(),'screens'=>Editor::group_screens()],
 'cfg'=>$cfg,'state'=>['version'=>1,'published_at'=>time(),'has_draft'=>false,'draft_at'=>0,'history'=>[],'checks'=>[],'live'=>true,'published_copy_utm'=>(object)[],'published_copy'=>(object)[],'pages_images'=>(object)['how'=>(object)[],'li'=>(object)[]],'li_items'=>array_map(function($it){return ['img'=>'','blur'=>!empty($it['blur'])];},$cfg['li']['items'])],
 'gateways'=>[],'products'=>$prods,'rewards'=>['tix'=>'Билет','tix3'=>'3 билета','tix5'=>'5 билета','ship'=>'Безплатна доставка','book'=>'Книга','cosm50'=>'Сет −50%','cosm1'=>'Сетът за €1'],
 'pages'=>Config::page_sections(),'gate_images'=>$gate,'gate_slots'=>Config::gate_image_slots()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
