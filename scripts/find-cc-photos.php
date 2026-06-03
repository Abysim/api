<?php
/**
 * Flickr CC photo finder for BigCats articles.
 * v3: full_body_priority mode — biases inspect pool toward wide/action shots.
 * v4: adult_only mode — biases against cub/juvenile tags, boosts adult tags.
 * Usage: FLICKR_KEY=<key> p scripts/find-cc-photos.php
 */

define('FLICKR_KEY', getenv('FLICKR_KEY') ?: (static function () {
    fwrite(STDERR, "Error: FLICKR_KEY env var is required.\nUsage: FLICKR_KEY=<key> p scripts/find-cc-photos.php\n");
    exit(1);
})());
define('FLICKR_API', 'https://api.flickr.com/services/rest/');
define('TAMBAKO_NSID', '8070463@N03');
define('CANDIDATES_WANTED', 3);
define('INSPECT_BUFFER', 8);
define('OUTPUT_DIR', __DIR__ . '/../storage/photo-candidates');
define('ACCEPTED_LICENSES', [4,5,6,7,9,10,11,12,13]);
define('LICENSE_STR', '4,5,6,7,9,10,11,12,13');
define('DIM_EXTRAS', 'license,owner_name,tags,url_k,url_h,url_l');

const LICENSE_NAMES = [4=>'CC BY 2.0',5=>'CC BY-SA 2.0',6=>'CC BY-ND 2.0',
    7=>'No known copyright restrictions',9=>'CC0 Public Domain',10=>'Public Domain Mark',
    11=>'CC BY 4.0',12=>'CC BY-SA 4.0',13=>'CC BY-ND 4.0'];

const ZOO_KEYWORDS = ['zoo','captive','captivity','enclosure','siky','siky park','basel',
    'marwell','papiliorama','plattli','plättli','parc des félins','beauval','bioparc',
    'tierpark','hellabrunn','zoological','wildlife park','safari park','animal park',
    'artis','ouwehands','dierenpark','wroclaw zoo','twycross','colchester zoo','whipsnade',
    'chester zoo','longleat','lausanne','multanova','frauenfeld','kerzers','rothenburg',
    'lucerne','crémines','cremines','jura park','alsace'];

const WILD_KEYWORDS = ['wild','wilderness','savanna','savannah','serengeti','kruger',
    'masai mara','national park','nature reserve','bandhavgarh','ranthambore','gir forest',
    'in the wild','free-roaming','free roaming','habitat','himalaya','himalayas','ladakh',
    'borneo','sumatra','rainforest','forest','jungle','tadoba','kanha','corbett','pench','nagarhole'];

// Wide-shot signals: boost in full_body mode
const FB_BOOST = ['walking','running','standing','lying','lying down','stalking','prowling',
    'full body','whole body','crouching','sitting','stretching','resting','wandering','moving','stretched'];

// Tight-crop signals: penalise in full_body mode
const FB_PENALISE = ['face','portrait','close','closeup','close-up','eyes','nose','whiskers',
    'head','headshot','muzzle','tongue','yawn','yawning'];

// Adult-only mode: push down cub/juvenile tags (score -10), boost adult tags (+2)
const ADULT_PENALISE = ['cub','cubs','kitten','kittens','baby','juvenile','young','youngster','infant'];
const ADULT_BOOST    = ['adult','male','female','mature','large','big'];

const ARTICLES = [
    ['id'=>100272,'slug'=>'snow-leopard','title_uk'=>'Сніговий барс','species'=>'snow leopard',
     'melanism_tags'=>null,'full_body'=>false],
    ['id'=>102522,'slug'=>'puma','title_uk'=>'Пума','species'=>'puma',
     'melanism_tags'=>null,'full_body'=>true],
    ['id'=>104100,'slug'=>'ocelot','title_uk'=>'Оцелот','species'=>'ocelot',
     'melanism_tags'=>'ocelot,melanistic','full_body'=>true],
    ['id'=>109255,'slug'=>'serval','title_uk'=>'Сервал','species'=>'serval',
     'melanism_tags'=>'serval,melanistic','full_body'=>true],
    ['id'=>110841,'slug'=>'caracal','title_uk'=>'Каракал','species'=>'caracal',
     'melanism_tags'=>'caracal,melanistic','full_body'=>false],
    ['id'=>186401,'slug'=>'clouded-leopard','title_uk'=>'Димчастий леопард','species'=>'clouded leopard',
     'melanism_tags'=>null,'full_body'=>false],
];

function flickr_get(array $p): ?array {
    $p += ['api_key'=>FLICKR_KEY,'format'=>'json','nojsoncallback'=>1];
    $ctx = stream_context_create(['http'=>['timeout'=>15,'user_agent'=>'BigCats-PhotoFinder/1.0']]);
    $raw = @file_get_contents(FLICKR_API.'?'.http_build_query($p), false, $ctx);
    usleep(350000);
    if (!$raw) { echo "  [WARN] HTTP failed\n"; return null; }
    $d = json_decode($raw, true);
    if (($d['stat']??'') !== 'ok') { echo "  [WARN] Flickr: ".($d['message']??'?')."\n"; return null; }
    return $d;
}

function best_size(array $p): ?array {
    foreach (['k','h','l'] as $s) {
        $u=$p["url_{$s}"]??null; $w=(int)($p["width_{$s}"]??0); $h=(int)($p["height_{$s}"]??0);
        if ($u&&$w&&$h) return ['url'=>$u,'width'=>$w,'height'=>$h];
    }
    return null;
}

function zoo_score(string $title, string $tags): int {
    $hay = strtolower("$title $tags"); $s=0;
    foreach (ZOO_KEYWORDS  as $k) if (str_contains($hay,$k)) $s++;
    foreach (WILD_KEYWORDS as $k) if (str_contains($hay,$k)) $s-=2;
    return $s;
}

function quality_score(string $title, string $tags, string $desc,
                        string $nsid, int $lic, int $zoo, bool $fb=false, bool $adult=false): int {
    $s=0; $tl=strtolower($title); $tg=strtolower($tags); $dl=strtolower($desc);
    foreach (['black','melanistic','melanism','white','leucistic','leucism'] as $w) {
        if (str_contains($tl,$w)) $s+=5;
        if (str_contains($tg,$w)) $s+=3;
        if (str_contains($dl,$w)) $s+=1;
    }
    if (in_array($lic,[5,6,12,13],true)) $s+=2;
    foreach (['albino','albinism','albinistic'] as $w)
        if (str_contains($tl,$w)||str_contains($tg,$w)) $s-=20;
    if ($nsid===TAMBAKO_NSID) $s+=4;
    $s -= max(0,($zoo*$zoo+$zoo)/2);
    if ($fb) {
        foreach (FB_BOOST    as $k) if (str_contains($tg,$k)||str_contains($tl,$k)) { $s+=4; break; }
        foreach (FB_PENALISE as $k) if (str_contains($tg,$k)||str_contains($tl,$k)) { $s-=6; break; }
    }
    if ($adult) {
        foreach (ADULT_PENALISE as $k) if (str_contains($tg,$k)||str_contains($tl,$k)) { $s-=10; break; }
        foreach (ADULT_BOOST    as $k) if (str_contains($tg,$k)||str_contains($tl,$k)) { $s+=2;  break; }
    }
    return $s;
}

function search_flickr(array $ov): array {
    $res = flickr_get(array_merge(['method'=>'flickr.photos.search','license'=>LICENSE_STR,
        'per_page'=>50,'extras'=>DIM_EXTRAS,'safe_search'=>1], $ov));
    return $res['photos']['photo']??[];
}

function filter_albino(array $photos): array {
    return array_values(array_filter($photos, function($p) {
        $h=strtolower(($p['title']??'').' '.($p['tags']??''));
        foreach (['albino','albinism','albinistic'] as $w) if (str_contains($h,$w)) return false;
        return true;
    }));
}

function rank_pool(bool $fb, bool $adult, array ...$pools): array {
    $seen=[]; $ranked=[];
    foreach ($pools as $pool) foreach ($pool as $p) {
        $pid=$p['id']; if (isset($seen[$pid])) continue; $seen[$pid]=true;
        $sz=best_size($p); if (!$sz||$sz['width']<=$sz['height']) continue;
        $lic=(int)($p['license']??0);
        if (!in_array($lic,ACCEPTED_LICENSES,true)) continue;
        $nsid=$p['owner']??''; $title=$p['title']??''; $tags=$p['tags']??'';
        $zoo=zoo_score($title,$tags);
        $ranked[]=['id'=>$pid,'owner_nsid'=>$nsid,'owner_name'=>$p['ownername']??'',
            'title'=>$title,'description'=>'','tags'=>$tags,'license_id'=>$lic,
            'license_name'=>LICENSE_NAMES[$lic]??"License $lic",'date_taken'=>'','date_posted'=>'',
            'page_url'=>"https://www.flickr.com/photos/{$nsid}/{$pid}/",
            'source_url'=>$sz['url'],'width'=>$sz['width'],'height'=>$sz['height'],
            'zoo_score'=>$zoo,'quality_score'=>quality_score($title,$tags,'',$nsid,$lic,$zoo,$fb,$adult),
            'rationale'=>'','visual_pass'=>null,'visual_notes'=>''];
    }
    usort($ranked,fn($a,$b)=>$b['quality_score']<=>$a['quality_score']);
    return $ranked;
}

function enrich_top(array &$cands, int $n, bool $fb, bool $adult=false): void {
    $i=0;
    foreach ($cands as &$c) {
        if ($i++>=$n) break;
        $r=flickr_get(['method'=>'flickr.photos.getInfo','photo_id'=>$c['id']]);
        if (!$r) continue;
        $info=$r['photo'];
        $c['description']=substr($info['description']['_content']??'',0,300);
        $c['date_taken']=$info['dates']['taken']??'';
        $c['date_posted']=date('Y-m-d',(int)($info['dates']['posted']??0));
        $rn=trim($info['owner']['realname']??''); $un=trim($info['owner']['username']??'');
        if ($rn) $c['owner_name']=$rn; elseif ($un) $c['owner_name']=$un;
        $ft=implode(', ',array_map(fn($t)=>$t['raw']??'',$info['tags']['tag']??[]));
        if ($ft) $c['tags']=$ft;
        $c['zoo_score']=zoo_score($c['title'],$c['tags']);
        $c['quality_score']=quality_score($c['title'],$c['tags'],$c['description'],
            $c['owner_nsid'],$c['license_id'],$c['zoo_score'],$fb,$adult);
    }
    unset($c);
    usort($cands,fn($a,$b)=>$b['quality_score']<=>$a['quality_score']);
}

function download_image(string $url, string $dest): bool {
    $ctx=stream_context_create(['http'=>['timeout'=>30,'user_agent'=>'BigCats-PhotoFinder/1.0']]);
    $d=@file_get_contents($url,false,$ctx); usleep(200000);
    return $d!==false && file_put_contents($dest,$d)!==false;
}

function backup_v1(string $dir): void {
    foreach (['1','2','3'] as $n) {
        $s="$dir/{$n}.jpg"; $d="$dir/{$n}-v1.jpg";
        if (file_exists($s)&&!file_exists($d)) rename($s,$d);
    }
    if (file_exists("$dir/candidates.json")&&!file_exists("$dir/candidates-v1.json"))
        rename("$dir/candidates.json","$dir/candidates-v1.json");
}

function backup_v2(string $dir): void {
    foreach (['1','2','3'] as $n) {
        $s="$dir/{$n}.jpg"; $d="$dir/{$n}-v2.jpg";
        if (file_exists($s)&&!file_exists($d)) rename($s,$d);
    }
    if (file_exists("$dir/candidates.json")&&!file_exists("$dir/candidates-v2.json"))
        rename("$dir/candidates.json","$dir/candidates-v2.json");
}

// ── Main ──────────────────────────────────────────────────────────────────────
if (!is_dir(OUTPUT_DIR)) mkdir(OUTPUT_DIR,0755,true);

foreach (ARTICLES as $art) {
    $nid=$art['id']; $slug=$art['slug']; $species=$art['species'];
    $fb=$art['full_body']??false; $buf=$fb?16:INSPECT_BUFFER;
    $tag1=explode(' ',$species)[0];

    echo "\n=== [{$nid}] {$art['title_uk']} ({$species})".($fb?' [FULL-BODY]':'')." ===\n";
    $dir=OUTPUT_DIR."/news-{$nid}-{$slug}";
    if (!is_dir($dir)) mkdir($dir,0755,true);
    backup_v1($dir);

    $pools=[];
    if ($art['melanism_tags']) {
        echo "  Pass A: melanism [{$art['melanism_tags']}]\n";
        $r=filter_albino(search_flickr(['tags'=>$art['melanism_tags'],'tag_mode'=>'all','sort'=>'relevance']));
        echo "  -> ".count($r)."\n"; $pools[]=$r;
    }
    echo "  Pass B: Tambako [{$species}]\n";
    $r=filter_albino(search_flickr(['user_id'=>TAMBAKO_NSID,'text'=>$species,'sort'=>'relevance','per_page'=>50]));
    echo "  -> ".count($r)."\n"; $pools[]=$r;

    echo "  Pass C: general text [{$species}]\n";
    $r=filter_albino(search_flickr(['text'=>$species,'sort'=>'interestingness-desc']));
    echo "  -> ".count($r)."\n"; $pools[]=$r;

    echo "  Pass D: general tag [{$tag1}]\n";
    $r=filter_albino(search_flickr(['tags'=>$tag1,'sort'=>'interestingness-desc']));
    echo "  -> ".count($r)."\n"; $pools[]=$r;

    if ($fb) {
        foreach (['walking','standing','stalking'] as $act) {
            echo "  Pass E: [{$species} {$act}]\n";
            $r=filter_albino(search_flickr(['text'=>"$species $act",'sort'=>'interestingness-desc']));
            echo "  -> ".count($r)."\n"; $pools[]=$r;
        }
    }

    $adult=$art['adult_only']??false;
    $ranked=rank_pool($fb,$adult,...$pools);
    echo "  Pool after landscape filter: ".count($ranked)."\n";
    if (empty($ranked)) {
        echo "  [WARN] No candidates\n";
        file_put_contents("$dir/candidates.json",json_encode([]));
        continue;
    }

    $top=array_slice($ranked,0,$buf);
    echo "  Enriching top ".count($top)."...\n";
    enrich_top($top,count($top),$fb,$adult);

    $inspect_prefix=$adult?'adult-inspect':'inspect';
    echo "  Downloading up to {$buf} inspect images...\n";
    $n=0;
    foreach ($top as &$c) {
        $n++; $dest="$dir/{$inspect_prefix}-{$n}.jpg";
        $ml=[];
        foreach (['black','melanistic','melanism','white','leucistic','leucism'] as $w)
            if (str_contains(strtolower($c['title']),$w)||str_contains(strtolower($c['tags']),$w)) $ml[]=$w;
        $c['rationale']="zoo={$c['zoo_score']},q={$c['quality_score']}".($ml?';ml:'.implode(',',$ml):'')
            .';by='.($c['owner_nsid']===TAMBAKO_NSID?'Tambako':$c['owner_name']);
        $c['inspect_file']=null;
        if ($c['source_url']&&download_image($c['source_url'],$dest)) {
            $c['inspect_file']="{$inspect_prefix}-{$n}.jpg";
            echo "  [{$n}] {$c['title']} ({$c['width']}x{$c['height']})\n";
        } else echo "  [{$n}] WARN download failed\n";
    }
    unset($c);

    file_put_contents("$dir/candidates.json",
        json_encode($top,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    echo "  Done. Ready for visual inspection.\n";
}
echo "\nAll done.\n";
