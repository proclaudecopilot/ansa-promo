<?php
/* v1.24.0: window.AnsaPromoEditor за статичния тест на редактора (tests/harness/editor.js) — без WordPress: регистърът, групите, екраните,
 * продуктите от seed-а, две кацания, state с публикувано кацане/статистика/непознати стойности. */
require __DIR__.'/../wpstub.php';
use AnsaPromo\Config; use AnsaPromo\Copy; use AnsaPromo\Editor; use AnsaPromo\Seed; use AnsaPromo\Doctor;
list($cfg)=Seed::build(false); $cfg=Config::normalize($cfg); $cfg['page_id']=7; $cfg['copy']=['gate.title.noutm'=>'ОСНОВА: до −{{max_pct}}%'];
$cfg['landings']=[['slug'=>'kozmetika','name'=>'Козметика','desc'=>'FB','enabled'=>true,'core'=>'','box'=>'l','know'=>'no','gate'=>'default','copy'=>['gate.title.noutm'=>'Козметиката за <s>€{{cosm_value}}</s> €{{cosm_pay_l}}']],
 ['slug'=>'greens','name'=>'Грийнс','desc'=>'','enabled'=>true,'core'=>'greens','box'=>'m','know'=>'yes','gate'=>'off','copy'=>[]]];
$cfg=Config::normalize($cfg);
$keys=[]; foreach(Copy::registry() as $g=>$ks){ foreach($ks as $k=>$v){ $keys[$k]=['g'=>$g,'d'=>$v,'v'=>Copy::placeholders($v)]; } }
$prods=[]; foreach($cfg['products'] as $p){ $prods[]=['key'=>$p['key'],'name'=>$p['name']?:$p['key'],'enabled'=>!empty($p['enabled']),'pid'=>(int)$p['product_id']]; }
$pub=$cfg; $pub['landings'][0]['copy']['gate.title.noutm']='ПУБЛИКУВАНО'; array_pop($pub['landings']);
$pl=[]; foreach($pub['landings'] as $l){ $pl[$l['slug']]=['enabled'=>$l['enabled'],'core'=>$l['core'],'box'=>$l['box'],'know'=>$l['know'],'gate'=>$l['gate'],'copy'=>(object)$l['copy']]; }
$D=['ajax'=>'','nonce'=>'x','ver'=>ANSA_PROMO_VER,'page'=>'','page_url'=>'https://ansa.bg/sezonut-na-ansa/','admin'=>'#','products_tab'=>'#',
 'registry'=>['groups'=>Copy::groups(),'keys'=>$keys,'vars'=>Copy::vars(),'screens'=>Editor::group_screens()],
 'cfg'=>Editor::out($cfg),'state'=>['version'=>3,'published_at'=>time(),'has_draft'=>true,'draft_at'=>time(),'history'=>[],'checks'=>Doctor::landing_checks($cfg),'live'=>true,'published_copy'=>(object)[],
   'published_landings'=>(object)$pl,'utm_stats'=>(object)['kozmetika'=>['n'=>12,'orders'=>2],'sakura'=>['n'=>5,'orders'=>0]],'unknown'=>[['k'=>'kozmetica','n'=>3,'last'=>'2026-10-20 10:00:00']]],
 'gateways'=>['cod'=>'Наложен платеж'],'products'=>$prods,'landing_groups'=>Copy::landing_groups(),'landing_knows'=>Config::landing_knows(),'landing_gates'=>Config::landing_gates(),
 'rewards'=>['tix'=>'Билет','tix3'=>'3 билета','tix5'=>'5 билета','ship'=>'Безплатна доставка','book'=>'Книга','cosm50'=>'Сет −50%','cosm1'=>'Сетът за €1']];
echo json_encode($D,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
