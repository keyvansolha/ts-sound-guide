<?php
/** Recommendation rules; deterministic, without invented sound/call-quality scores. */
function ts_sound_normalize(array $input): array {
    $valid = ['flow'=>['earbuds','headphones'],'use'=>['commute','music','work','gaming','gift'],'pain'=>['noise','fit','calls','switch','charge','balanced'],'connection'=>['wireless','usbc','aux'],'device'=>['usbc','lightning','unknown'],'fit'=>['silicone','open','any','long','glasses'],'calls'=>['quiet','noisy','none']];
    $a=[];
    foreach($valid as $key=>$values) if(isset($input[$key]) && in_array($input[$key],$values,true)) $a[$key]=$input[$key];
    $a['flow']=$a['flow']??'earbuds';
    $a['budget']=max(500000,min(500000000,is_numeric($input['budget']??null)?(float)$input['budget']:6000000));
    $a['flex']=($input['flex']??false)===true;
    if(($a['connection']??'')!=='usbc')unset($a['device']);
    if(($a['use']??'')!=='work'&&($a['pain']??'')!=='calls')unset($a['calls']);
    if($a['flow']==='headphones'&&in_array($a['fit']??'', ['open','silicone'],true))unset($a['fit']);
    return $a;
}
function ts_sound_available(array $p,float $cap=INF):array {
    if(($p['status']??'')!=='publish'||($p['lifecycle']??'')==='stop'||($p['inStock']??false)!==true||($p['purchasable']??false)!==true)return [];
    return array_values(array_filter($p['variants']??[],static function($v)use($cap){
        return !in_array($v['status']??'publish',['private','draft'],true)&&($v['enabled']??true)!==false&&($v['inStock']??true)!==false&&!in_array($v['stockStatus']??'instock',['onbackorder','outofstock'],true)&&($v['backorder']??false)!==true&&$v['price']>0&&$v['price']<=$cap&&($v['qty']===null||(float)$v['qty']>(float)($v['held']??0));
    }));
}
function ts_sound_select(array $input,array $catalog):array {
    $a=ts_sound_normalize($input);$cap=$a['budget']*($a['flex']?1.2:1);$matched=[];$rejected=[];
    $use=$a['use']??'';$pain=$a['pain']??'';$conn=$a['connection']??'';$fit=$a['fit']??'';
    $features=['anc'=>'کاهش صدای محیط با ANC','multipoint'=>'اتصال هم‌زمان گوشی و لپ‌تاپ'];
    foreach($catalog as $p){
        $reason=null;$vs=ts_sound_available($p,$cap);
        if($p['flow']!==$a['flow'])$reason='category';
        elseif(!ts_sound_available($p))$reason='unavailable';
        elseif(!$vs)$reason='budget';
        elseif($conn==='wireless'&&$p['wireless']!==true)$reason='connection';
        elseif($conn==='usbc'&&($p['usbc']!==true||($a['device']??'')!=='usbc'))$reason='device';
        elseif($conn==='aux'&&$p['aux']!==true)$reason='connection';
        elseif($pain==='noise'&&$p['anc']!==true)$reason='anc';
        elseif($pain==='switch'&&$p['multipoint']!==true)$reason='multipoint';
        elseif($fit==='open'&&$p['silicone']!==false)$reason='fit';
        elseif($fit==='silicone'&&$p['silicone']!==true)$reason='fit';
        elseif(($use==='work'||$pain==='calls')&&$conn==='aux'&&$p['auxMic']!==true)$reason='wired_microphone';
        elseif($use==='gaming'&&$conn==='wireless')$reason='gaming_latency';
        if($reason){$rejected[]=['id'=>$p['id'],'reason'=>$reason];continue;}
        usort($vs,static fn($x,$y)=>($x['price']<=>$y['price'])?:($x['id']<=>$y['id']));$price=$vs[0]['price'];$score=50;$reasons=[];
        if($pain==='noise'&&$p['anc']===true){$score+=25;$reasons[]=$features['anc'];}
        if($pain==='switch'&&$p['multipoint']===true){$score+=25;$reasons[]=$features['multipoint'];}
        if($use==='commute'&&$p['anc']===true){$score+=12;if(!in_array($features['anc'],$reasons,true))$reasons[]=$features['anc'];}
        if($use==='work'&&$p['multipoint']===true){$score+=10;if(!in_array($features['multipoint'],$reasons,true))$reasons[]=$features['multipoint'];}
        if($conn==='usbc')$reasons[]='اتصال سیمی USB-C؛ بدون نیاز به شارژ';
        if($conn==='aux')$reasons[]='ورودی AUX تأییدشده';
        if($fit==='open')$reasons[]='بدون سری سیلیکونی داخل گوش';
        if($fit==='silicone')$reasons[]='سری سیلیکونی قابل تعویض';
        if(!$reasons)$reasons[]='نوع اتصال مطابق انتخاب شما';
        $reasons[]=$price<=$a['budget']?'در محدوده بودجه شما':'در محدوده افزایش بودجه‌ای که انتخاب کردید';
        $qty=0;$owners=[];foreach($vs as $v){$owner=$v['stockOwnerId']??$v['id'];if(isset($owners[$owner]))continue;$owners[$owner]=true;$qty+=($v['qty']===null?0:max(0,$v['qty']-($v['held']??0)));}
        $matched[]=array_merge($p,['price'=>$price,'variants'=>$vs,'fitScore'=>$score,'reasons'=>$reasons,'qty'=>$qty,'overBudget'=>$price>$a['budget']]);
    }
    usort($matched,static fn($x,$y)=>($y['fitScore']<=>$x['fitScore'])?:($x['price']<=>$y['price'])?:($x['id']<=>$y['id']));
    if($matched){$top=$matched[0];$ties=array_values(array_filter($matched,static fn($p)=>$p['fitScore']===$top['fitScore']&&$p['price']===$top['price']));usort($ties,static fn($x,$y)=>($y['qty']<=>$x['qty'])?:($x['id']<=>$y['id']));if($ties[0]['id']!==$top['id']){foreach($matched as $i=>$p)if($p['id']===$ties[0]['id']){array_splice($matched,$i,1);break;}array_unshift($matched,$ties[0]);}}
    $picks=[];
    if($matched){$picks[]=array_merge($matched[0],['role'=>'پیشنهاد اصلی']);$first=$matched[0];
        $values=array_values(array_filter($matched,static fn($p)=>$p['id']!==$first['id']&&$p['price']<$first['price']));usort($values,static fn($x,$y)=>($x['price']<=>$y['price'])?:($y['fitScore']<=>$x['fitScore']));if($values)$picks[]=array_merge($values[0],['role'=>'هزینه کمتر']);
        $desired=[];if($use==='commute')$desired[]='anc';if($use==='work')$desired[]='multipoint';
        $upgrades=[];foreach($matched as $p){if(in_array($p['id'],array_column($picks,'id'),true)||$p['price']<=$first['price'])continue;foreach($desired as $f)if($p[$f]===true&&$first[$f]!==true){$upgrades[]=$p;break;}}
        usort($upgrades,static fn($x,$y)=>$x['price']<=>$y['price']);if($upgrades){$up=$upgrades[0];$extra=[];foreach($desired as $f)if($up[$f]===true&&$first[$f]!==true)$extra[]=$features[$f];$picks[]=array_merge($up,['role'=>'امکانات بیشتر','upgradeReason'=>implode('، ',$extra)]);}
        if(count($picks)<3)foreach($matched as $p)if(!in_array($p['id'],array_column($picks,'id'),true)&&($p['anc']!==$first['anc']||$p['multipoint']!==$first['multipoint']||$p['silicone']!==$first['silicone']||$p['wireless']!==$first['wireless'])){$picks[]=array_merge($p,['role'=>$p['price']<$first['price']?'هزینه کمتر':'گزینه جایگزین']);break;}
    }
    $notices=[];
    if(($a['calls']??'')==='noisy')$notices[]='کیفیت میکروفون در محیط شلوغ به تست نیاز دارد؛ ANC تضمین کیفیت تماس نیست.';
    if($pain==='fit'||in_array($fit,['long','glasses'],true))$notices[]='راحتی و فشار روی گوش شخصی است؛ این پیشنهادها تضمین جاگیری یا راحتی طولانی نیستند.';
    if($conn==='usbc'&&($a['device']??'')==='usbc')$notices[]='USB-C بودن درگاه به‌تنهایی کافی نیست؛ پشتیبانی صوتی مدل دستگاه را پیش از خرید بررسی کنید.';
    if($use==='gaming'&&$conn==='wireless')$notices[]='برای بازی با تأخیر حساس، در سبد فعلی اتصال بی‌سیم آزموده‌شده نداریم؛ مسیر سیمی را بررسی کنید.';
    if($pain==='charge'&&$conn==='wireless')$notices[]='زمان شارژدهی در شرایط یکسان برای تمام این مدل‌ها تأیید نشده؛ رتبه‌بندی را بر اساس عددهای غیرقابل مقایسه تغییر نداده‌ایم.';
    return ['answers'=>$a,'picks'=>array_slice($picks,0,3),'matched'=>$matched,'rejected'=>$rejected,'notices'=>$notices,'total'=>count($matched),'version'=>'2.0.0'];
}
