<?php
require __DIR__.'/../wpstub.php';
use AnsaPromo\Config; use AnsaPromo\Copy; use AnsaPromo\Seed;
list($cfg,$rep)=Seed::build(false); $cfg=Config::normalize($cfg);
/* v1.19.0: седмиците на сезона — ANSA_WEEK=N (по подразбиране 3) рендира харнеса като N-тата седмица; ANSA_WEEK=0 = без седмици */
date_default_timezone_set('Europe/Sofia'); $WEEK=getenv('ANSA_WEEK'); $WEEK=('' === (string)$WEEK) ? 3 : (int)$WEEK; $season=['on'=>false];
if($WEEK>0){ $cfg['season']['weekly']=true; $lad=[]; for($i=0;$i<10;$i++){ $lad[]=['s'=>15-$i,'m'=>30-2*$i,'l'=>40-3*$i]; } $cfg['season']['weeks']=$lad; $cfg=Config::normalize($cfg);
  $rows=Config::season_weeks($cfg); $w=$rows[min($WEEK,count($rows))-1]; Config::set_now($w['from']+4*86400+10*3600); $cfg=Config::at($cfg); $cur=$cfg['season']['cur'];
  $dow=['неделя','понеделник','вторник','сряда','четвъртък','петък','събота']; $dm=function($t){return date('j.m',$t);}; $dw=function($t)use($dow){return $dow[(int)date('w',$t)];};
  $season=['on'=>true,'week'=>$cur['i'],'total'=>$cur['total'],'from'=>$dm($cur['from']),'to'=>$dm($cur['to']-60),'to_day'=>$dw($cur['to']-60),'ends'=>$cur['to'],'now'=>Config::now(),'days_left'=>$cur['days_left'],'last'=>$cur['last'],
    'next'=>$cur['next']?$cur['next']['pct']:null,'next_from'=>$cur['next']?$dw($cur['next']['from']).' '.$dm($cur['next']['from']):'','grace_hours'=>24,
    'ladder'=>array_map(function($r)use($dm){return ['i'=>$r['i'],'from'=>$dm($r['from']),'to'=>$dm($r['to']-60),'pct'=>$r['pct']];},$rows)]; }
$prices=['greens'=>39.90,'sakura'=>44.90,'red'=>42.90,'berber'=>39.90,'meno'=>45.90,'thyro'=>42.90,'yoni'=>34.90,'ahcc'=>49.90,'veno'=>29.90,'ashwa'=>27.90];
$prods=[]; $i=100;
foreach($cfg['products'] as $p){ $i++; $prods[$p['key']]=['key'=>$p['key'],'id'=>$i,'vid'=>0,'ph'=>$p['ph'],'img'=>'','name'=>$p['name'],'gname'=>$p['gname'],'gsub'=>$p['gsub'],'ds'=>$p['ds'],'desc'=>$p['desc'],'ing'=>$p['ing'],'who'=>$p['who'],'rating'=>$p['rating'],'pack'=>$p['pack'],
  'reviews'=>array_values(array_filter(array_map(function($l){$x=array_map('trim',explode('|',$l));return count($x)>=3?['n'=>$x[0],'s'=>(int)$x[1],'t'=>$x[2]]:null;},preg_split('/\r?\n/',(string)$p['reviews'])))),
  'cat'=>$p['cat'],'theme'=>$p['theme'],'price'=>$prices[$p['key']],'stock'=>true,
  /* v1.14.0: отстъпка по кутия — yoni е с override −42% в Голямата (пример), останалите на процента на кутията */
  'pct'=>(object)array_combine(array_column($cfg['boxes'],'id'),array_map(function($b)use($p){ if('yoni'===$p['key']){ $p['pct']=['l'=>42]; } return Config::product_pct($p,$b); },$cfg['boxes']))]; }
$utm=$argv[1]??'sakura';
/* снимките в попъпа: в WP ги дава Frontend::gate_images(); тук — примерните SVG-та от assets/img/gate/ */
$gate=$cfg['gate']; $gate['images']=[]; foreach(array_keys(Config::gate_image_slots()) as $k){ $gate['images'][$k]='file://'.ANSA_PROMO_PATH.'assets/img/gate/'.$k.'.svg'; }
/* 1.24.21: секциите на /info и /kozmetika — редът/скритите от конфига (ANSA_PAGES=JSON override за теста), без свои снимки → promo.js пада на тези от попъпа */
$pages=[]; $ovr=json_decode((string)getenv('ANSA_PAGES'),true); foreach(Config::page_sections() as $pg=>$m){ $pc=$cfg['pages'][$pg]; if(is_array($ovr[$pg]??null)){ $pc=array_merge($pc,$ovr[$pg]); }
  $pages[$pg]=['order'=>array_values($pc['order']),'off'=>array_values($pc['off']),'images'=>(object)($pc['images']??[]),'labels'=>(object)array_map(function($s){return $s['l'];},$m['sections']),'img'=>(object)array_map(function($s){return $s['img'];},$m['sections'])]; }
$rt=['ver'=>ANSA_PROMO_VER,'pages'=>$pages,'id'=>$cfg['id'],'name'=>$cfg['name'],'version'=>1,'ajax'=>'','nonce'=>'x','live'=>true,'draft'=>false,'editor'=>!empty($argv[2]),'cart'=>false,'refresh'=>false,
 'checkout'=>['cod'=>true],'products'=>(object)$prods,'order'=>array_keys($prods),'fit'=>(object)$cfg['fit'],'problems'=>$cfg['problems'],'boxes'=>$cfg['boxes'],'max_packs'=>Config::max_packs($cfg),'box_order'=>$cfg['order'],
 'rewards'=>$cfg['rewards'],'secret'=>$cfg['secret'],'ship'=>$cfg['ship']['paid'],'timer'=>$cfg['timer'],'gate'=>$gate,'bgn'=>$cfg['bgn'],'utm'=>$utm,'deadline'=>'31.12.2026','season'=>$season,'mode'=>!empty($argv[3])?$argv[3]:'','promo_url'=>'https://ansa.bg/sezonut-na-ansa/','info_url'=>'https://ansa.bg/sezonut-na-ansa/info/','tombola_url'=>'https://ansa.bg/sezonut-na-ansa/tombola/','draw'=>'10.01.2027','cosm_reserved'=>1000,'copy'=>$WEEK>0?Copy::weekly(Copy::effective([]),[]):Copy::effective([]),'registry'=>null];
echo json_encode($rt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
