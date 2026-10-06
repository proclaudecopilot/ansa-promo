<?php
require __DIR__.'/../wpstub.php';
use AnsaPromo\Config; use AnsaPromo\Copy; use AnsaPromo\Seed;
list($cfg,$rep)=Seed::build(false); $cfg=Config::normalize($cfg);
$prices=['greens'=>39.90,'sakura'=>44.90,'red'=>42.90,'berber'=>39.90,'meno'=>45.90,'thyro'=>42.90,'yoni'=>34.90,'ahcc'=>49.90,'veno'=>29.90,'ashwa'=>27.90];
$prods=[]; $i=100;
foreach($cfg['products'] as $p){ $i++; $prods[$p['key']]=['key'=>$p['key'],'id'=>$i,'vid'=>0,'ph'=>$p['ph'],'img'=>'','name'=>$p['name'],'gname'=>$p['gname'],'gsub'=>$p['gsub'],'ds'=>$p['ds'],'desc'=>$p['desc'],'ing'=>$p['ing'],'who'=>$p['who'],'rating'=>$p['rating'],'pack'=>$p['pack'],
  'reviews'=>array_values(array_filter(array_map(function($l){$x=array_map('trim',explode('|',$l));return count($x)>=3?['n'=>$x[0],'s'=>(int)$x[1],'t'=>$x[2]]:null;},preg_split('/\r?\n/',(string)$p['reviews'])))),
  'cat'=>$p['cat'],'theme'=>$p['theme'],'price'=>$prices[$p['key']],'stock'=>true]; }
$utm=$argv[1]??'sakura';
/* снимките в попъпа: в WP ги дава Frontend::gate_images(); тук — примерните SVG-та от assets/img/gate/ */
$gate=$cfg['gate']; $gate['images']=[]; foreach(array_keys(Config::gate_image_slots()) as $k){ $gate['images'][$k]='file://'.ANSA_PROMO_PATH.'assets/img/gate/'.$k.'.svg'; }
$rt=['ver'=>ANSA_PROMO_VER,'id'=>$cfg['id'],'name'=>$cfg['name'],'version'=>1,'ajax'=>'','nonce'=>'x','live'=>true,'draft'=>false,'editor'=>!empty($argv[2]),'cart'=>false,'refresh'=>false,
 'checkout'=>['cod'=>true],'products'=>(object)$prods,'order'=>array_keys($prods),'fit'=>(object)$cfg['fit'],'problems'=>$cfg['problems'],'boxes'=>$cfg['boxes'],'box_order'=>$cfg['order'],
 'rewards'=>$cfg['rewards'],'secret'=>$cfg['secret'],'ship'=>$cfg['ship']['paid'],'timer'=>$cfg['timer'],'gate'=>$gate,'bgn'=>$cfg['bgn'],'utm'=>$utm,'deadline'=>'31.12.2026','copy'=>Copy::effective([]),'registry'=>null];
echo json_encode($rt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
