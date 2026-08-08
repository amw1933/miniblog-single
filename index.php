<?php
$GLOBALS['EDITORMD_ASSETS']=[];

$GLOBALS['SITE_FONT_B64']='';
/**

 * MiniBlog - 单文件 PHP 博客系统
 * 部署时自动生成所需目录和数据库
 */
$GLOBALS['SITE_FONTS_EXTRA']=[];
$GLOBALS['SEED_ARTICLES_B64']='';
define('DB_DIR',__DIR__.'/data');
define('DB_FILE',DB_DIR.'/blog.db');
define('BACKUP_DIR',DB_DIR.'/backup');
define('UPLOAD_DIR',__DIR__.'/uploads');
define('SITE_NAME','MiniBlog');
define('SITE_URL',(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https://':'http://').$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/'));
define('POSTS_PER_PAGE',10);
define('UPLOAD_MAX_SIZE',500*1024*1024);
define('REMOTE_IMAGE_MAX_BYTES',10*1024*1024);
define('REMOTE_IMAGE_MAX_PER_SAVE',10);
define('COMMENT_RATE_MAX',5);
define('COMMENT_RATE_WINDOW',300);
date_default_timezone_set('Asia/Shanghai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$action=$_GET['action']??'';
$slug=$_GET['slug']??'';

try{
  if(!is_dir(DB_DIR))@mkdir(DB_DIR,0755,true);
  if(!is_dir(BACKUP_DIR))@mkdir(BACKUP_DIR,0755,true);
  if(!is_dir(UPLOAD_DIR))@mkdir(UPLOAD_DIR,0755,true);
  try{ensureEditorAssets();}catch(Exception $e){}
  try{ensureSiteFont();}catch(Exception $e){}
  $needSetup=false;
  $db=new PDO('sqlite:'.DB_FILE);
  $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $db->exec('PRAGMA journal_mode=WAL');
  $db->exec('PRAGMA busy_timeout=5000');
  $db->exec('CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT)');
  $db->exec('CREATE TABLE IF NOT EXISTS categories(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT UNIQUE NOT NULL,description TEXT DEFAULT "",parent_id INTEGER DEFAULT 0,sort_order INTEGER DEFAULT 0)');
  try{$db->exec("SELECT parent_id FROM categories LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE categories ADD COLUMN parent_id INTEGER DEFAULT 0");}
  try{$db->exec("SELECT sort_order FROM categories LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE categories ADD COLUMN sort_order INTEGER DEFAULT 0");}
  $db->exec('CREATE TABLE IF NOT EXISTS posts(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,slug TEXT UNIQUE NOT NULL,content TEXT,excerpt TEXT,category_id INTEGER,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,published INTEGER DEFAULT 1,views INTEGER DEFAULT 0,publish_at DATETIME,deleted_at DATETIME,password TEXT,notified INTEGER DEFAULT 0)');
  $db->exec('CREATE TABLE IF NOT EXISTS comments(id INTEGER PRIMARY KEY AUTOINCREMENT,post_id INTEGER NOT NULL,author TEXT NOT NULL,email TEXT,content TEXT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,approved INTEGER DEFAULT 0,parent_id INTEGER DEFAULT 0,ip TEXT,ua TEXT)');
  try{$db->exec("SELECT publish_at FROM posts LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE posts ADD COLUMN publish_at DATETIME");}
  try{$db->exec("SELECT parent_id FROM comments LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE comments ADD COLUMN parent_id INTEGER DEFAULT 0");}
  try{$db->exec("SELECT deleted_at FROM posts LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE posts ADD COLUMN deleted_at DATETIME");}
  try{$db->exec("SELECT password FROM posts LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE posts ADD COLUMN password TEXT");}
  try{$db->exec("SELECT notified FROM posts LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE posts ADD COLUMN notified INTEGER DEFAULT 0");}
  try{$db->exec("SELECT pinned FROM posts LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE posts ADD COLUMN pinned INTEGER DEFAULT 0");}
  try{$db->exec("SELECT ip FROM comments LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE comments ADD COLUMN ip TEXT");}
  try{$db->exec("SELECT ua FROM comments LIMIT 1");}catch(Exception $e){$db->exec("ALTER TABLE comments ADD COLUMN ua TEXT");}
  $db->exec('CREATE TABLE IF NOT EXISTS remote_image_cache(url TEXT PRIMARY KEY,local TEXT NOT NULL,size INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
  $db->exec('CREATE TABLE IF NOT EXISTS comment_quota(ip TEXT PRIMARY KEY,cnt INTEGER DEFAULT 0,window INTEGER DEFAULT 0)');
  $db->exec('CREATE TABLE IF NOT EXISTS login_attempts(ip TEXT PRIMARY KEY,cnt INTEGER DEFAULT 0,window INTEGER DEFAULT 0)');
  $db->exec('CREATE TABLE IF NOT EXISTS tags(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE NOT NULL,slug TEXT UNIQUE NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS post_tags(post_id INTEGER NOT NULL,tag_id INTEGER NOT NULL,PRIMARY KEY(post_id,tag_id))');
$db->exec('CREATE TABLE IF NOT EXISTS post_categories(post_id INTEGER NOT NULL,category_id INTEGER NOT NULL,PRIMARY KEY(post_id,category_id))');
$db->exec('CREATE INDEX IF NOT EXISTS idx_post_categories_cat ON post_categories(category_id)');
try{$db->exec('INSERT OR IGNORE INTO post_categories(post_id,category_id) SELECT id,category_id FROM posts WHERE category_id>0');}catch(Exception $e){}
  $db->exec('CREATE TABLE IF NOT EXISTS stats_daily(date TEXT PRIMARY KEY,pv INTEGER DEFAULT 0,uv INTEGER DEFAULT 0,posts INTEGER DEFAULT 0)');
  $db->exec('CREATE TABLE IF NOT EXISTS visit_ips(ip TEXT NOT NULL,day TEXT NOT NULL,visits INTEGER DEFAULT 1,ua TEXT,first_at DATETIME,last_at DATETIME,PRIMARY KEY(ip,day))');
  $db->exec('CREATE TABLE IF NOT EXISTS admin_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,action TEXT NOT NULL,detail TEXT,ip TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
  $db->exec('CREATE TABLE IF NOT EXISTS comment_blacklist(ip TEXT PRIMARY KEY,note TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
  $db->exec('CREATE TABLE IF NOT EXISTS search_log(keyword TEXT PRIMARY KEY,hits INTEGER DEFAULT 0,last_at DATETIME)');
  $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_created ON posts(created_at)');
  $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_cat ON posts(category_id,published)');
  $db->exec('CREATE INDEX IF NOT EXISTS idx_comments_post ON comments(post_id,approved)');
  $db->exec('CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag_id)');
  $adminExists=$db->query("SELECT value FROM settings WHERE key='admin_user'")->fetch();
  if(!$adminExists)$needSetup=true;
  $useFts=false;
  try{
    $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(title,content,tokenize='trigram')");
    $db->exec("CREATE TRIGGER IF NOT EXISTS posts_fts_ai AFTER INSERT ON posts BEGIN INSERT INTO posts_fts(rowid,title,content) VALUES(new.id,new.title,new.content); END;");
    $db->exec("CREATE TRIGGER IF NOT EXISTS posts_fts_ad AFTER DELETE ON posts BEGIN DELETE FROM posts_fts WHERE rowid=old.id; END;");
    $db->exec("CREATE TRIGGER IF NOT EXISTS posts_fts_au AFTER UPDATE ON posts BEGIN DELETE FROM posts_fts WHERE rowid=old.id; INSERT INTO posts_fts(rowid,title,content) VALUES(new.id,new.title,new.content); END;");
    $useFts=true;
    $pCount=$db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $fCount=$db->query("SELECT COUNT(*) FROM posts_fts")->fetchColumn();
    if($pCount!=$fCount){
      $db->exec("DELETE FROM posts_fts");
      $db->exec("INSERT INTO posts_fts(rowid,title,content) SELECT id,title,content FROM posts");
    }
  }catch(Exception $e){$useFts=false;}
  seedDefaultArticles($db);
  $now=date('Y-m-d H:i:s');
  try{$db->prepare("UPDATE posts SET published=1 WHERE published=0 AND publish_at IS NOT NULL AND publish_at<=?")->execute([$now]);}catch(Exception $e){}
  try{
    $due=$db->prepare("SELECT id,title,slug,excerpt FROM posts WHERE published=1 AND publish_at IS NOT NULL AND publish_at<=? AND notified=0 AND deleted_at IS NULL");
    $due->execute([$now]);$duePosts=$due->fetchAll(PDO::FETCH_ASSOC);
    foreach($duePosts as $dp){$db->prepare("UPDATE posts SET notified=1 WHERE id=?")->execute([$dp['id']]);notifyPublish($db,$dp);}
  }catch(Exception $e){}
  try{
    $lastBackup=setting($db,'last_backup_at');
    if($action===''&&!isset($_GET['admin'])&&(!$lastBackup||(time()-strtotime($lastBackup))>24*3600)){
      if(rand(1,10)===1){
        register_shutdown_function(function()use($db){
          try{$snap=createBackupSnapshot($db);if($snap&&empty($snap['running']))$db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES('last_backup_at',?)")->execute([date('Y-m-d H:i:s')]);}catch(Exception $e){}
        });
      }
    }
  }catch(Exception $e){}
}catch(Exception $e){
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>安装错误</title><link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <style>body{font-family:system-ui,sans-serif;padding:40px;max-width:600px;margin:0 auto;background:#f4f6f9}.box{background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.3rem;color:#dc2626;margin-bottom:12px}p{color:#5a6a7a;line-height:1.6;font-size:.9rem;}</style>
  <link rel="stylesheet" href="editormd/css/editormd.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
</head><body><div class="box"><h1>⚠️ 安装错误</h1><p>'.htmlspecialchars($e->getMessage()).'</p></div></body></html>';
  exit;
}

if(!headers_sent())session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>false,'httponly'=>false,'samesite'=>'Lax']);
session_start();
function isAdmin(){return!empty($_SESSION['blog_admin']);}
function json($d,$c=200){http_response_code($c);header('Content-Type:application/json;charset=utf-8');exit(json_encode($d,JSON_UNESCAPED_UNICODE));}

function csrf_token(){
    if(empty($_SESSION['csrf_token'])){
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(){
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['token'] ?? '');
    if(empty($token) || !hash_equals(csrf_token(), $token)){
        json(['error'=>'安全校验失败，请刷新页面'],403);
    }
}

function loadPostTags($db,$ids){
  if(!$ids)return [];
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $stmt=$db->prepare("SELECT pt.post_id,t.id,t.name,t.slug FROM post_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.post_id IN ($ph) ORDER BY t.name");
  $stmt->execute(array_values($ids));
  $map=[];
  foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)$map[$r['post_id']][]=['id'=>$r['id'],'name'=>$r['name'],'slug'=>$r['slug']];
  return $map;
}
function loadPostCats($db,$id){
  $q=$db->prepare("SELECT c.id,c.name FROM post_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.post_id=? ORDER BY c.name");
  $q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

function savePostTags($db,$postId,$tagsStr){
  $db->prepare("DELETE FROM post_tags WHERE post_id=?")->execute([$postId]);
  $names=[];
  foreach(preg_split('/[,，\s]+/u',trim($tagsStr)) as $t){$t=trim($t);if($t!=='')$names[]=$t;}
  $names=array_unique($names);
  foreach($names as $name){
    if(mb_strlen($name,'UTF-8')>30)continue;
    $slug=preg_replace('/[^a-z0-9]+/','-',mb_strtolower($name,'UTF-8'));$slug=trim($slug,'-');
    if($slug==='')$slug='tag-'.substr(md5($name),0,8);
    $db->prepare("INSERT OR IGNORE INTO tags(name,slug)VALUES(?,?)")->execute([$name,$slug]);
    $q=$db->prepare("SELECT id FROM tags WHERE slug=?");$q->execute([$slug]);$tid=$q->fetchColumn();
    if($tid)$db->prepare("INSERT OR IGNORE INTO post_tags(post_id,tag_id)VALUES(?,?)")->execute([$postId,$tid]);
  }
}

function setting($db,$key,$default=''){
  $q=$db->prepare("SELECT value FROM settings WHERE key=?");$q->execute([$key]);
  $v=$q->fetchColumn();
  return ($v===false||$v===null)?$default:$v;
}

function uploadDefaultExts(){
  return ['jpg','jpeg','png','gif','webp','svg','bmp','ico','rar','zip','exe','txt','md','log','csv','json','xml'];
}

function uploadMaxBytes($db){
  $mb=intval(setting($db,'upload_max_mb',UPLOAD_MAX_SIZE/1048576));
  if($mb<=0)return PHP_INT_MAX; // 0 = 不限制单文件大小
  if($mb>10240)$mb=10240;
  return $mb*1048576;
}

function uploadAllowedExts($db){
  $raw=trim(setting($db,'upload_exts',''));
  if($raw==='')return uploadDefaultExts();
  $out=[];$parts=preg_split('/[,，\s]+/',strtolower($raw));
  foreach($parts as $p){$p=trim($p,'.');if(preg_match('/^[a-z0-9]{1,10}$/',$p)&&!in_array($p,$out,true))$out[]=$p;}
  return $out;
}

function ensureEditorAssets(){
  $root=__DIR__.'/editormd';
  if(is_file($root.'/editormd.min.js')&&filesize($root.'/editormd.min.js')>10000&&is_file($root.'/lib/mermaid.min.js')&&filesize($root.'/lib/mermaid.min.js')>100000)return;
  $map=$GLOBALS['EDITORMD_ASSETS']??[];
  if(!$map)return;
  foreach($map as $rel=>$b64){
    $p=$root.'/'.$rel;
    $dir=dirname($p);
    if(!is_dir($dir))@mkdir($dir,0755,true);
    $data=editorAssetDecode($b64);
    if($data!==false)@file_put_contents($p,$data);
  }
}
function editorAssetDecode($b64){
  $data=base64_decode($b64,true);
  if($data===false)return false;
  if(isset($data[1])&&ord($data[0])===0x1f&&ord($data[1])===0x8b){
    if(function_exists('gzdecode'))return @gzdecode($data);
    if(function_exists('gzinflate'))return @gzinflate(substr($data,10,-8));
    return false;
  }
  return $data;
}
function ensureSiteFont(){
  $dir=__DIR__.'/fonts';
  $files=['ma-shan-zheng.woff2'=>$GLOBALS['SITE_FONT_B64']??''];
  if(!empty($GLOBALS['SITE_FONTS_EXTRA'])&&is_array($GLOBALS['SITE_FONTS_EXTRA']))foreach($GLOBALS['SITE_FONTS_EXTRA'] as $name=>$b64)$files[$name]=$b64;
  if(!is_dir($dir))@mkdir($dir,0755,true);
  foreach($files as $name=>$b64){
    $p=$dir.'/'.$name;
    if(is_file($p)&&filesize($p)>5000)continue;
    if($b64==='')continue;
    $d=editorAssetDecode($b64);
    if($d!==false)@file_put_contents($p,$d);
  }
}
function seedDefaultArticles($db){
  try{
    if(setting($db,'seed_articles_done')!=='')return;
    $raw=base64_decode($GLOBALS['SEED_ARTICLES_B64']??'',true);
    $list=$raw===false?null:json_decode($raw,true);
    if(!is_array($list)){
      $seedFile=__DIR__.'/seed/articles.json';
      if(is_file($seedFile))$list=json_decode(file_get_contents($seedFile),true);
    }
    if(!is_array($list))return;
    $exists=$db->prepare("SELECT COUNT(*) FROM posts WHERE slug=?");
    foreach($list as $a){
      $slug=trim($a['slug']??'');if($slug==='')continue;
      $exists->execute([$slug]);if((int)$exists->fetchColumn()>0)continue;
      $catId=0;$catName=trim($a['category']??'');
      if($catName!==''){
        $cq=$db->prepare("SELECT id FROM categories WHERE name=?");$cq->execute([$catName]);$catId=(int)$cq->fetchColumn();
        if(!$catId){
          $db->prepare("INSERT INTO categories(name,slug,description)VALUES(?,?,?)")->execute([$catName,'cat-'.substr(md5($catName),0,8),'']);
          $catId=(int)$db->lastInsertId();
        }
      }
      $db->prepare("INSERT INTO posts(title,slug,content,excerpt,category_id,published,created_at,updated_at,notified)VALUES(?,?,?,?,?,1,?,?,1)")->execute([$a['title'],$slug,$a['content'],$a['excerpt']??'',$catId,$a['created_at'],$a['updated_at']]);
      $pid=(int)$db->lastInsertId();
      foreach(($a['tags']??[]) as $t){
        $t=trim($t);if($t==='')continue;
        $ts=preg_replace('/[^a-z0-9]+/','-',mb_strtolower($t,'UTF-8'));$ts=trim($ts,'-');
        if($ts==='')$ts='tag-'.substr(md5($t),0,8);
        $db->prepare("INSERT OR IGNORE INTO tags(name,slug)VALUES(?,?)")->execute([$t,$ts]);
        $q=$db->prepare("SELECT id FROM tags WHERE slug=?");$q->execute([$ts]);$tid=(int)$q->fetchColumn();
        if($tid)$db->prepare("INSERT OR IGNORE INTO post_tags(post_id,tag_id)VALUES(?,?)")->execute([$pid,$tid]);
      }
    }
    $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES('seed_articles_done','1')")->execute();
  }catch(Exception $e){}
}

function trackVisit($db){
  $today=date('Y-m-d');
  $db->prepare("INSERT OR IGNORE INTO stats_daily(date,pv,uv,posts)VALUES(?,0,0,0)")->execute([$today]);
  $newUv=(empty($_SESSION['uv_date'])||$_SESSION['uv_date']!==$today)?1:0;
  $db->prepare("UPDATE stats_daily SET pv=pv+1,uv=uv+? WHERE date=?")->execute([$newUv,$today]);
  $_SESSION['uv_date']=$today;
  $ip=trim($_SERVER['REMOTE_ADDR']??'');
  foreach(['HTTP_X_REAL_IP','HTTP_CF_CONNECTING_IP'] as $k){
    if(!empty($_SERVER[$k])&&filter_var(trim($_SERVER[$k]),FILTER_VALIDATE_IP)){$ip=trim($_SERVER[$k]);break;}
  }
  if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
    $xff=trim(explode(',',$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if(filter_var($xff,FILTER_VALIDATE_IP))$ip=$xff;
  }
  if($ip===''||strlen($ip)>64||!filter_var($ip,FILTER_VALIDATE_IP))$ip='0.0.0.0';
  $ua=mb_substr(trim($_SERVER['HTTP_USER_AGENT']??''),0,150);
  $nowSql=date('Y-m-d H:i:s');
  $db->prepare("INSERT OR IGNORE INTO visit_ips(ip,day,visits,ua,first_at,last_at)VALUES(?,?,1,?,?,?)")->execute([$ip,$today,$ua,$nowSql,$nowSql]);
  $db->prepare("UPDATE visit_ips SET visits=visits+1,ua=?,last_at=? WHERE ip=? AND day=?")->execute([$ua,$nowSql,$ip,$today]);
  if(mt_rand(1,100)===1)$db->exec("DELETE FROM visit_ips WHERE day < date('now','-90 days')");
}

function sendNotify($db,$title,$body,$link='',$telegram=false){
  $webhook=setting($db,'notify_webhook');
  if($webhook&&function_exists('curl_init')){
    $ch=curl_init($webhook);
    $postFields=(strpos($webhook,'sctapi.ftqq.com')!==false)?http_build_query(['title'=>$title,'desp'=>$body]):json_encode(['title'=>$title,'content'=>$body]);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>8,CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$postFields]);
    if(strpos($webhook,'sctapi.ftqq.com')===false)curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);
    curl_exec($ch);curl_close($ch);
  }
  if($telegram){
    $tb=setting($db,'telegram_bot');
    $tc=setting($db,'telegram_chat');
    if($tb&&$tc&&function_exists('curl_init')){
      $ch=curl_init('https://api.telegram.org/bot'.$tb.'/sendMessage');
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>8,CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$tc,'text'=>$title."\n".$link,'disable_web_page_preview'=>true])]);
      curl_exec($ch);curl_close($ch);
    }
  }
  $email=setting($db,'notify_email');
  if($email&&filter_var($email,FILTER_VALIDATE_EMAIL))@mail($email,$title,$body,'Content-Type: text/plain; charset=utf-8');
}

function notifyComment($db,$postInfo,$author,$content){
  $title='MiniBlog 新评论：'.($postInfo['title']??'');
  $body="作者：$author\n内容：$content\n链接：".SITE_URL.'/?slug='.urlencode($postInfo['slug']??'');
  sendNotify($db,$title,$body);
}

function notifyPublish($db,$post){
  $title='MiniBlog 新文章：'.($post['title']??'');
  $link=SITE_URL.'/?slug='.urlencode($post['slug']??'');
  $body="标题：".($post['title']??'')."\n摘要：".strip_tags($post['excerpt']??'')."\n链接：".$link;
  sendNotify($db,$title,$body,$link,true);
}

function addLog($db,$action,$detail=''){
  try{$db->prepare("INSERT INTO admin_logs(action,detail,ip,created_at)VALUES(?,?,?,?)")->execute([$action,mb_substr((string)$detail,0,500),$_SERVER['REMOTE_ADDR']??'',date('Y-m-d H:i:s')]);}catch(Exception $e){}
}

function postWords($content){
  $t=preg_replace('/```.*?```/s','',$content);
  $t=preg_replace('/[#*_`>\[\]!()\-~|]/u',' ',$t);
  $t=preg_replace('/!video\[.*?\]\(.*?\)/','',$t);
  $n=mb_strlen(preg_replace('/\s+/u','',strip_tags($t)),'UTF-8');
  return ['words'=>$n,'minutes'=>max(1,intval(ceil($n/400)))];
}

function backupFileSet(){
  $root=__DIR__;
  $map=['index.php'=>__FILE__];
  foreach(['robots.txt','sitemap.xml'] as $rel){$abs=$root.'/'.$rel;if(is_file($abs))$map[$rel]=$abs;}
  foreach(glob($root.'/data/*.db')?:[] as $f)$map['data/'.basename($f)]=$f;
  foreach(['data/.htaccess','uploads/.htaccess'] as $rel){$abs=$root.'/'.$rel;if(is_file($abs))$map[$rel]=$abs;}
  foreach(['editormd','fonts','uploads'] as $dir){
    $base=$root.'/'.$dir;
    if(!is_dir($base))continue;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
      if(!$f->isFile())continue;
      if(preg_match('/^thumbs\.db$/i',$f->getFilename()))continue;
      $rel=$dir.'/'.str_replace('\\','/',substr($f->getPathname(),strlen($base)+1));
      $map[$rel]=$f->getPathname();
    }
  }
  return $map;
}

function backupRoot(){
  global $db;
  $custom='';
  if(isset($db))$custom=trim(setting($db,'backup_dir'));
  if($custom!==''){
    if(!preg_match('#^(/|[A-Za-z]:[\\/]|\\\\)#',$custom))$custom=__DIR__.'/'.$custom;
    if(is_dir($custom)||@mkdir($custom,0755,true))return rtrim($custom,'/\\');
  }
  return BACKUP_DIR;
}

function backupManifest($read=true){
  $root=backupRoot();
  $mf=$root.'/manifest.json';
  if($read&&is_file($mf)){
    $d=json_decode(file_get_contents($mf),true);
    return is_array($d)?$d:['files'=>[]];
  }
  return ['files'=>[]];
}

function rrmdir($dir){
  if(!is_dir($dir))return;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
  @rmdir($dir);
}

function migrateSnapshotRefs($root,$oldName,$newest,$newestName){
  $manifest=backupManifest(true);$migrated=false;
  foreach($manifest['files'] as $rel=>$info){
    if(strpos($info['store'],$oldName.'/')===0){
      $src=$root.'/'.$info['store'];
      if(is_file($src)){
        $dest=$newest.'/'.$rel;@mkdir(dirname($dest),0755,true);
        if(copy($src,$dest)){$manifest['files'][$rel]['store']=$newestName.'/'.$rel;$migrated=true;}
      }
    }
  }
  if($migrated)file_put_contents($root.'/manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  return $migrated;
}

function createBackupSnapshot($db){
  $root=backupRoot();
  if(!is_dir($root))@mkdir($root,0755,true);
  $lockFile=$root.'/backup.lock';
  if(is_file($lockFile)&&(time()-filemtime($lockFile))<600)return ['running'=>true,'since'=>date('Y-m-d H:i:s',filemtime($lockFile))];
  @file_put_contents($lockFile,time());
  try{$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');}catch(Exception $e){}
  $prev=backupManifest(true);
  $files=backupFileSet();
  $stamp=date('Ymd-His');
  $snapDir=$root.'/snapshots/'.$stamp;
  if(!is_dir($snapDir))@mkdir($snapDir,0755,true);
  $manifest=[];$changed=0;$total=0;
  foreach($files as $rel=>$abs){
    $total++;
    $size=filesize($abs);$mtime=filemtime($abs);
    $store=null;
    if(isset($prev['files'][$rel])&&$prev['files'][$rel]['size']===$size&&$prev['files'][$rel]['mtime']===$mtime){
      $store=$prev['files'][$rel]['store'];
      if(!is_file($root.'/'.$store))$store=null;
    }
    if($store===null){
      $dest=$snapDir.'/'.$rel;@mkdir(dirname($dest),0755,true);
      if(copy($abs,$dest)){$store='snapshots/'.$stamp.'/'.$rel;$changed++;}
    }
    $manifest[$rel]=['size'=>$size,'mtime'=>$mtime,'store'=>$store];
  }
  file_put_contents($root.'/manifest.json',json_encode(['created_at'=>date('Y-m-d H:i:s'),'stamp'=>$stamp,'total'=>$total,'files'=>$manifest],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  $dirs=glob($root.'/snapshots/*',GLOB_ONLYDIR)?:[];
  usort($dirs,function($a,$b){return strcmp(basename($b),basename($a));});
  while(count($dirs)>10){
    $old=$dirs[count($dirs)-1];$oldName='snapshots/'.basename($old);
    $newest=$dirs[0];$newestName='snapshots/'.basename($newest);
    migrateSnapshotRefs($root,$oldName,$newest,$newestName);
    rrmdir($old);
    $dirs=glob($root.'/snapshots/*',GLOB_ONLYDIR)?:[];
    usort($dirs,function($a,$b){return strcmp(basename($b),basename($a));});
  }
  @unlink($lockFile);
  $fullSize=0;$fullCount=0;
  foreach($files as $abs){$fullSize+=filesize($abs);$fullCount++;}
  return ['stamp'=>$stamp,'changed'=>$changed,'total'=>$total,'full_size'=>$fullSize,'full_count'=>$fullCount];
}

function buildBackupZip($db,$outPath){
  $root=backupRoot();
  $manifest=backupManifest(true);
  if(empty($manifest['files'])||!class_exists('ZipArchive'))return false;
  $missing=false;
  foreach($manifest['files'] as $rel=>$info){if(empty($info['store'])||!is_file($root.'/'.$info['store'])){$missing=true;break;}}
  if($missing){
    $snap=createBackupSnapshot($db);
    if(empty($snap['running']))$manifest=backupManifest(true);
  }
  $zip=new ZipArchive();
  if($zip->open($outPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)return false;
  foreach($manifest['files'] as $rel=>$info){
    $src=$root.'/'.$info['store'];
    if(is_file($src))$zip->addFile($src,$rel);
  }
  $zip->close();
  return is_file($outPath);
}

function removeBackupSnapshot($stamp){
  $root=backupRoot();
  $dir=$root.'/snapshots/'.basename($stamp);
  if(!is_dir($dir))return false;
  $oldName='snapshots/'.basename($stamp);
  $dirs=glob($root.'/snapshots/*',GLOB_ONLYDIR)?:[];
  usort($dirs,function($a,$b){return strcmp(basename($b),basename($a));});
  $newest=null;
  foreach($dirs as $d){if(realpath($d)!==realpath($dir)){$newest=$d;break;}}
  if($newest)migrateSnapshotRefs($root,$oldName,$newest,'snapshots/'.basename($newest));
  rrmdir($dir);
  return true;
}

if($action==='setup'&&$needSetup){
  $d=json_decode(file_get_contents('php://input'),true);
  $user=trim($d['user']??'');$pass=trim($d['pass']??'');
  if(!$user||!$pass)json(['error'=>'请填写用户名和密码'],400);
  $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES(?,?)")->execute(['admin_user',password_hash($user,PASSWORD_BCRYPT)]);
  $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES(?,?)")->execute(['admin_pass',password_hash($pass,PASSWORD_BCRYPT)]);
  $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES(?,?)")->execute(['site_name',SITE_NAME]);
  $db->exec("INSERT OR IGNORE INTO categories(name,slug,description)VALUES('默认分类','default','默认文章分类')");
  $_SESSION['blog_admin']=true;json(['ok'=>true]);
}
if($action==='login'){
  $d=json_decode(file_get_contents('php://input'),true);
  $ip=$_SERVER['REMOTE_ADDR']??'';$lt=time();
  $qa=$db->prepare("SELECT cnt,window FROM login_attempts WHERE ip=?");$qa->execute([$ip]);$la=$qa->fetch(PDO::FETCH_ASSOC);
  if($la&&($lt-intval($la['window']))<900&&intval($la['cnt'])>=5)json(['error'=>'尝试次数过多，请15分钟后再试'],429);
  $u=$db->query("SELECT value FROM settings WHERE key='admin_user'")->fetchColumn();
  $p=$db->query("SELECT value FROM settings WHERE key='admin_pass'")->fetchColumn();
  if(password_verify($d['user']??'',$u)&&password_verify($d['pass']??'',$p)){
    $_SESSION['blog_admin']=true;session_regenerate_id(true);
    $db->prepare("DELETE FROM login_attempts WHERE ip=?")->execute([$ip]);
    addLog($db,'login','管理员登录成功');
    json(['ok'=>true]);
  }
  if(!$la||($lt-intval($la['window']))>=900)$db->prepare("INSERT OR REPLACE INTO login_attempts(ip,cnt,window)VALUES(?,1,?)")->execute([$ip,$lt]);
  else $db->prepare("UPDATE login_attempts SET cnt=cnt+1 WHERE ip=?")->execute([$ip]);
  json(['error'=>'用户名或密码错误'],401);
}
if($action==='logout'){
  $_SESSION=[];
  if(ini_get('session.use_cookies')){
    $p=session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
  }
  session_destroy();
  header('Location: ?');exit;
}
if($action==='check'){json(['admin'=>isAdmin(),'setup'=>$needSetup]);}
if($action==='posts'){ try{
  $page=max(1,intval($_GET['page']??1));$cat=intval($_GET['cat']??0);$search=trim($_GET['search']??'');$tagSlug=trim($_GET['tag']??'');
  $offset=($page-1)*POSTS_PER_PAGE;$where='WHERE p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?)';$params=[$now];
  if($cat>0){
    $catIds=[$cat];
    $subQ=$db->prepare("SELECT id FROM categories WHERE parent_id=?");$subQ->execute([$cat]);
    foreach($subQ->fetchAll(PDO::FETCH_COLUMN) as $sid)$catIds[]=$sid;
    $placeholders=implode(',',array_fill(0,count($catIds),'?'));
    $where.=" AND (p.category_id IN ($placeholders) OR p.id IN (SELECT pc.post_id FROM post_categories pc WHERE pc.category_id IN ($placeholders)))";$params=array_merge($params,$catIds,$catIds);
  }
  if($search){
    try{$db->prepare("INSERT INTO search_log(keyword,hits,last_at)VALUES(?,1,?) ON CONFLICT(keyword) DO UPDATE SET hits=hits+1,last_at=?")->execute([$search,$now,$now]);}catch(Exception $e){}
    if($useFts && mb_strlen($search,'UTF-8')>=3){
      $where.=" AND p.id IN (SELECT rowid FROM posts_fts WHERE posts_fts MATCH ?)";
      $params[]='"'.str_replace('"','""',$search).'"';
    }else{
      $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';
      $where.=" AND (p.title LIKE ? ESCAPE '\\' OR p.content LIKE ? ESCAPE '\\')";
      $params[]=$like;$params[]=$like;
    }
  }
  if($tagSlug!==''){
    $where.=" AND p.id IN (SELECT pt.post_id FROM post_tags pt JOIN tags t ON t.id=pt.tag_id WHERE t.slug=?)";
    $params[]=$tagSlug;
  }
  $total=$db->prepare("SELECT COUNT(*)FROM posts p $where");$total->execute($params);$total=$total->fetchColumn();
  $stmt=$db->prepare("SELECT p.*,c.name as cat_name,(SELECT COUNT(*) FROM comments cm WHERE cm.post_id=p.id AND cm.approved=1) as comment_count FROM posts p LEFT JOIN categories c ON p.category_id=c.id $where ORDER BY p.pinned DESC,p.created_at DESC LIMIT ? OFFSET ?");
  $params[]=POSTS_PER_PAGE;$params[]=$offset;$stmt->execute($params);
  $posts=$stmt->fetchAll(PDO::FETCH_ASSOC);
  $tagsMap=loadPostTags($db,array_column($posts,'id'));
  foreach($posts as&$p){
    $p['thumbnail']=getPostThumbnail($p['content'],$p['title']);
    $p['content']=$p['excerpt']?$p['excerpt']:mb_substr(strip_tags(md($p['content'])),0,200);
    $p['tags']=$tagsMap[$p['id']]??[];
    if(!empty($p['password'])){$p['locked']=1;$p['content']='';$p['excerpt']='';}
    unset($p['password']);
  }
  json(['posts'=>$posts,'total'=>intval($total),'page'=>$page,'pages'=>ceil($total/POSTS_PER_PAGE),'search'=>$search]);
  }catch(Exception $e){json(['error'=>'服务器错误：'.$e->getMessage()],500);}
}
if($action==='post'){
  $stmt=$db->prepare("SELECT p.*,c.name as cat_name FROM posts p LEFT JOIN categories c ON p.category_id=c.id WHERE p.slug=? AND p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?)");
  $stmt->execute([$slug,$now]);$post=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!$post)json(['error'=>'文章不存在'],404);
  $postId=(int)$post['id'];
  if(!empty($post['password'])&&empty($_SESSION['post_pw'][$postId])){unset($post['content'],$post['password'],$post['excerpt']);$post['requires_password']=1;json($post);}
  unset($post['password']);
  $db->prepare("UPDATE posts SET views=views+1 WHERE id=?")->execute([$post['id']]);
  trackVisit($db);
  $post['content_html']=mdWithAlt($post['content'],$post['title']);
  $wc=postWords($post['content']);$post['words']=$wc['words'];$post['minutes']=$wc['minutes'];
  $cs=$db->prepare("SELECT * FROM comments WHERE post_id=? AND approved=1 ORDER BY created_at ASC");$cs->execute([$post['id']]);
  $post['comments']=$cs->fetchAll(PDO::FETCH_ASSOC);
  $tagsMap=loadPostTags($db,[$post['id']]);$post['tags']=$tagsMap[$post['id']]??[];
  $post['cats']=array_map(function($x){return $x['name'];},loadPostCats($db,$post['id']));
  $related=[];
  $stmt=$db->prepare("SELECT DISTINCT p.id,p.slug,p.title,p.excerpt,p.created_at,p.views FROM posts p JOIN post_tags pt ON pt.post_id=p.id WHERE p.id<>? AND p.published=1 AND (p.publish_at IS NULL OR p.publish_at<=?) AND pt.tag_id IN (SELECT tag_id FROM post_tags WHERE post_id=?) ORDER BY p.views DESC LIMIT 5");
  $stmt->execute([$post['id'],$now,$post['id']]);$related=$stmt->fetchAll(PDO::FETCH_ASSOC);
  if(!$related){
    $catQ=$db->prepare("SELECT category_id FROM post_categories WHERE post_id=?");$catQ->execute([$post['id']]);$relCats=$catQ->fetchAll(PDO::FETCH_COLUMN);
    if($post['category_id']&&!in_array($post['category_id'],$relCats,true))$relCats[]=$post['category_id'];
    if($relCats){
      $ph=implode(',',array_fill(0,count($relCats),'?'));
      $stmt=$db->prepare("SELECT DISTINCT p.id,p.slug,p.title,p.excerpt,p.created_at,p.views FROM posts p WHERE p.id<>? AND (p.category_id IN ($ph) OR p.id IN (SELECT pc.post_id FROM post_categories pc WHERE pc.category_id IN ($ph))) AND p.published=1 AND (p.publish_at IS NULL OR p.publish_at<=?) ORDER BY p.views DESC LIMIT 5");
      $stmt->execute(array_merge([$post['id']],$relCats,$relCats,[$now]));$related=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
  }
  $post['related']=$related;
  $prevStmt=$db->prepare("SELECT slug,title FROM posts WHERE id<>? AND published=1 AND (publish_at IS NULL OR publish_at<=?) AND (created_at<? OR (created_at=? AND id<?)) ORDER BY created_at DESC,id DESC LIMIT 1");
  $prevStmt->execute([$post['id'],$now,$post['created_at'],$post['created_at'],$post['id']]);$post['prev']=$prevStmt->fetch(PDO::FETCH_ASSOC);
  $nextStmt=$db->prepare("SELECT slug,title FROM posts WHERE id<>? AND published=1 AND (publish_at IS NULL OR publish_at<=?) AND (created_at>? OR (created_at=? AND id>?)) ORDER BY created_at ASC,id ASC LIMIT 1");
  $nextStmt->execute([$post['id'],$now,$post['created_at'],$post['created_at'],$post['id']]);$post['next']=$nextStmt->fetch(PDO::FETCH_ASSOC);
  json($post);
}
if($action==='post_pw'){
  $slug=trim($_POST['slug']??$_GET['slug']??'');
  $password=(string)($_POST['pwd']??$_GET['pwd']??'');
  $stmt=$db->prepare("SELECT id,password FROM posts WHERE slug=? AND published=1 AND deleted_at IS NULL");$stmt->execute([$slug]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!$row||$row['password']===''){header('Location: ?slug='.urlencode($slug).'&pw=error');exit;}
  $ok=password_verify($password,$row['password']);
  if(!$ok)$ok=password_verify(trim($password),$row['password']);
  if(!$ok&&strpos($row['password'],'$2')!==0&&hash_equals($row['password'],$password))$ok=true;
  if(!$ok&&strpos($row['password'],'$2')===0&&function_exists('crypt')&&crypt($password,$row['password'])===$row['password'])$ok=true;
  if(!$ok){header('Location: ?slug='.urlencode($slug).'&pw=error');exit;}
  $_SESSION['post_pw'][(int)$row['id']]=true;
  session_write_close();
  header('Location: ?slug='.urlencode($slug));exit;
}
if($action==='comment'){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $pid=intval($d['post_id']??0);$author=trim($d['author']??'匿名');$email=trim($d['email']??'');$content=trim($d['content']??'');$parentId=intval($d['parent_id']??0);
  if(!$pid||!$content)json(['error'=>'请填写评论内容'],400);
  if(mb_strlen($content,'UTF-8')>2000)json(['error'=>'评论内容不能超过2000字'],400);
  if(mb_strlen($author,'UTF-8')>50)json(['error'=>'昵称不能超过50字'],400);
  if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))json(['error'=>'邮箱格式不正确'],400);
  if(empty($_SESSION['captcha_answer'])||(time()-intval($_SESSION['captcha_ts']??0))>300||intval($d['captcha']??'')!==intval($_SESSION['captcha_answer']))json(['error'=>'验证码不正确'],400);
  unset($_SESSION['captcha_answer'],$_SESSION['captcha_ts']);
  $exists=$db->prepare("SELECT COUNT(*) FROM posts WHERE id=? AND published=1 AND (publish_at IS NULL OR publish_at<=?)");$exists->execute([$pid,$now]);
  if(!$exists->fetchColumn())json(['error'=>'文章不存在'],404);
  if($parentId>0){
    $pc=$db->prepare("SELECT COUNT(*) FROM comments WHERE id=? AND post_id=?");$pc->execute([$parentId,$pid]);
    if(!$pc->fetchColumn())json(['error'=>'回复的评论不存在'],400);
  }
  $ip=$_SERVER['REMOTE_ADDR']??'';
  $bl=$db->prepare("SELECT COUNT(*) FROM comment_blacklist WHERE ip=?");$bl->execute([$ip]);
  if($bl->fetchColumn())json(['error'=>'该 IP 已被限制评论'],403);
  $keywords=trim(setting($db,'comment_keywords'));
  if($keywords!==''){
    foreach(explode(',',$keywords) as $kw){$kw=trim($kw);if($kw!==''&&(mb_strpos($content,$kw)!==false||mb_strpos($author,$kw)!==false))json(['error'=>'内容包含敏感词，无法提交'],400);}
  }
  $now=time();
  $q=$db->prepare("SELECT cnt,window FROM comment_quota WHERE ip=?");$q->execute([$ip]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row||($now-intval($row['window']))>COMMENT_RATE_WINDOW){$cnt=1;$win=$now;}
  else{$cnt=intval($row['cnt'])+1;$win=intval($row['window']);}
  if($cnt>COMMENT_RATE_MAX)json(['error'=>'评论太频繁，请稍后再试'],429);
  $db->prepare("INSERT OR REPLACE INTO comment_quota(ip,cnt,window)VALUES(?,?,?)")->execute([$ip,$cnt,$win]);
  $db->prepare("INSERT INTO comments(post_id,author,email,content,approved,parent_id,ip,ua)VALUES(?,?,?,?,0,?,?,?)")->execute([$pid,$author,$email,$content,$parentId,$ip,mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,300)]);
  $postInfo=$db->prepare("SELECT title,slug FROM posts WHERE id=?");$postInfo->execute([$pid]);$postInfo=$postInfo->fetch(PDO::FETCH_ASSOC);
  $postInfo=$postInfo?:['title'=>'','slug'=>''];
  notifyComment($db,$postInfo,$author,$content);
  json(['ok'=>true,'msg'=>'评论已提交，审核后显示']);
}
if($action==='categories'){
  $stmt=$db->prepare("SELECT c.*,(SELECT COUNT(*)FROM post_categories pc JOIN posts p ON p.id=pc.post_id WHERE pc.category_id=c.id AND p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?))as post_count FROM categories c ORDER BY c.name");
  $stmt->execute([$now]);
  json($stmt->fetchAll(PDO::FETCH_ASSOC));
}
if($action==='tags'){
  $stmt=$db->prepare("SELECT t.id,t.name,t.slug,COUNT(pt.post_id) c FROM tags t LEFT JOIN post_tags pt ON pt.tag_id=t.id LEFT JOIN posts p ON p.id=pt.post_id AND p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?) GROUP BY t.id ORDER BY c DESC,t.name");
  $stmt->execute([$now]);
  json($stmt->fetchAll(PDO::FETCH_ASSOC));
}
if($action==='rss'){
  $stmt=$db->prepare("SELECT p.*,c.name as cat_name FROM posts p LEFT JOIN categories c ON p.category_id=c.id WHERE p.published=1 AND p.deleted_at IS NULL AND (p.password IS NULL OR p.password='') AND (p.publish_at IS NULL OR p.publish_at<=?) ORDER BY p.created_at DESC LIMIT 20");
  $stmt->execute([$now]);$posts=$stmt->fetchAll(PDO::FETCH_ASSOC);
  header('Content-Type:application/rss+xml;charset=utf-8');
  $sn=setting($db,'site_name',SITE_NAME);
  echo '<?xml version="1.0"?><rss version="2.0"><channel><title>'.$sn.'</title><link>'.SITE_URL.'</link>';
  foreach($posts as $p){$link=SITE_URL.'/?slug='.urlencode($p['slug']);echo '<item><title>'.htmlspecialchars($p['title']).'</title><link>'.$link.'</link><guid>'.$link.'</guid><description>'.htmlspecialchars(strip_tags(md($p['content']))).'</description></item>';}
  echo '</channel></rss>';exit;
}
function buildSitemapXml($db){
  $now=date('Y-m-d H:i:s');
  $stmt=$db->prepare("SELECT slug,updated_at FROM posts WHERE published=1 AND deleted_at IS NULL AND (password IS NULL OR password='') AND (publish_at IS NULL OR publish_at<=?) ORDER BY updated_at DESC");
  $stmt->execute([$now]);$posts=$stmt->fetchAll(PDO::FETCH_ASSOC);
  $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.htmlspecialchars(SITE_URL.'/').'</loc><priority>1.0</priority></url>';
  foreach($posts as $p)$xml.='<url><loc>'.htmlspecialchars(SITE_URL.'/?slug='.urlencode($p['slug'])).'</loc><lastmod>'.substr($p['updated_at'],0,10).'</lastmod><priority>0.8</priority></url>';
  $ts=$db->query("SELECT slug FROM tags ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
  foreach($ts as $tag)$xml.='<url><loc>'.htmlspecialchars(SITE_URL.'/?tag='.urlencode($tag)).'</loc><priority>0.5</priority></url>';
  return $xml.'</urlset>';
}
function regenerateSitemapFile($db){
  $ok=@file_put_contents(__DIR__.'/sitemap.xml',buildSitemapXml($db));
  return $ok!==false;
}
if($action==='sitemap'){
  header('Content-Type:application/xml;charset=utf-8');
  echo buildSitemapXml($db);exit;
}
if($action==='robots'){
  header('Content-Type:text/plain;charset=utf-8');
  echo "User-agent: *\nAllow: /\nDisallow: /data/\nDisallow: /?admin=\nDisallow: /?action=login\nDisallow: /?action=logout\nDisallow: /?action=admin_\nDisallow: /?action=process_paste\nDisallow: /?action=render_md\nSitemap: ".SITE_URL."/?action=sitemap\n";exit;
}
if($action==='admin_regen_sitemap'&&isAdmin()){
  csrf_verify();
  $ok=regenerateSitemapFile($db);
  addLog($db,'sitemap_regen','重新生成 sitemap.xml'.($ok?'':'（目录不可写，生成失败）'));
  json(['ok'=>true,'written'=>$ok]);
}
if($action==='favicon'){
  $favThemes=['blue'=>['#2563eb','#7c3aed'],'warm'=>['#ff8a3d','#ff4e6b'],'light'=>['#ff8a3d','#ff4e6b'],'dark'=>['#a78bfa','#6366f1']];
  $ft=$_GET['theme']??'blue';
  if(!isset($favThemes[$ft]))$ft='blue';
  $fg1=$favThemes[$ft][0];$fg2=$favThemes[$ft][1];
  header('Cache-Control:public, max-age=86400');
  if(isset($_GET['png'])&&function_exists('imagecreatetruecolor')){
    $s=180;$im=imagecreatetruecolor($s,$s);
    $c1=[hexdec(substr($fg1,1,2)),hexdec(substr($fg1,3,2)),hexdec(substr($fg1,5,2))];
    $c2=[hexdec(substr($fg2,1,2)),hexdec(substr($fg2,3,2)),hexdec(substr($fg2,5,2))];
    for($y=0;$y<$s;$y++){
      $f=$y/($s-1);
      $col=imagecolorallocate($im,(int)round($c1[0]+($c2[0]-$c1[0])*$f),(int)round($c1[1]+($c2[1]-$c1[1])*$f),(int)round($c1[2]+($c2[2]-$c1[2])*$f));
      imageline($im,0,$y,$s-1,$y,$col);
    }
    $white=imagecolorallocate($im,255,255,255);
    imagefilledrectangle($im,50,28,130,152,$white);
    $line=imagecolorallocate($im,$c1[0],$c1[1],$c1[2]);
    imageline($im,72,68,108,68,$line);
    imageline($im,72,90,108,90,$line);
    imageline($im,72,112,96,112,$line);
    header('Content-Type:image/png');
    imagepng($im);imagedestroy($im);exit;
  }
  header('Content-Type:image/svg+xml;charset=utf-8');
  echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$fg1.'"/><stop offset="1" stop-color="'.$fg2.'"/></linearGradient></defs><rect x="1" y="1" width="30" height="30" rx="9" fill="url(#g)"/><path d="M9 5h14a2 2 0 0 1 2 2v20l-9-5-9 5V7a2 2 0 0 1 2-2z" fill="#fff"/><path d="M13 12h6M13 16h6M13 20h4" stroke="'.$fg1.'" stroke-width="1.4" stroke-linecap="round" fill="none"/></svg>';exit;
}
if($action==='admin_posts'&&isAdmin()){
  csrf_verify();
  $page=max(1,intval($_GET['page']??1));$offset=($page-1)*POSTS_PER_PAGE;
  $total=$db->query("SELECT COUNT(*)FROM posts WHERE deleted_at IS NULL")->fetchColumn();
  $stmt=$db->prepare("SELECT p.*,c.name as cat_name FROM posts p LEFT JOIN categories c ON p.category_id=c.id WHERE p.deleted_at IS NULL ORDER BY p.pinned DESC,p.created_at DESC LIMIT ? OFFSET ?");$stmt->execute([POSTS_PER_PAGE,$offset]);
  $posts=$stmt->fetchAll(PDO::FETCH_ASSOC);
  $tagsMap=loadPostTags($db,array_column($posts,'id'));
  foreach($posts as&$p){$p['tags']=$tagsMap[$p['id']]??[];$p['is_scheduled']=(!empty($p['publish_at'])&&$p['publish_at']>$now)?1:0;unset($p['password']);}
  json(['posts'=>$posts,'total'=>intval($total),'page'=>$page,'pages'=>ceil($total/POSTS_PER_PAGE)]);
}
if($action==='admin_set_pin'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);$pin=intval($_GET['pin']??0)?1:0;
  if($id<=0)json(['error'=>'参数错误'],400);
  $db->prepare("UPDATE posts SET pinned=? WHERE id=?")->execute([$pin,$id]);
  addLog($db,$pin?'post_pin':'post_unpin',($pin?'置顶':'取消置顶').'文章 #'.$id);
  json(['ok'=>true,'pinned'=>!!$pin]);
}
if($action==='admin_save'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $id=intval($d['id']??0);
  $title=trim($d['title']??'');
  $content=$d['content']??'';
  $cat=intval($d['category_id']??0);
  $pub=intval($d['published']??1);
  $publishAt=trim($d['publish_at']??'');$tagsStr=trim($d['tags']??'');$password=trim($d['password']??'');
  if(!$title)json(['error'=>'标题不能为空'],400);

  // ===== 处理发表时间（仅日期） =====
  $createdAt = trim($d['created_at'] ?? '');
  if($createdAt !== ''){
    // "2025-01-01" → "2025-01-01 00:00:00"
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdAt)){
      $createdAt .= ' 00:00:00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $createdAt);
    if(!$dt){
      json(['error'=>'发表时间格式不正确'],400);
    }
    $createdAt = $dt->format('Y-m-d H:i:s');
  }
  // ============================

  $publishAtDb=null;
  if($publishAt!==''){
    $publishAt=str_replace('T',' ',$publishAt);
    if(strlen($publishAt)===16)$publishAt.=':00';
    $dt=DateTime::createFromFormat('Y-m-d H:i:s',$publishAt);
    if(!$dt)json(['error'=>'定时发布时间格式不正确'],400);
    $publishAtDb=$dt->format('Y-m-d H:i:s');
  }
  $passwordHash=$password!==''?password_hash($password,PASSWORD_BCRYPT):null;
  // 自动下载远程图片到本地
  $content=downloadRemoteImages($content,$db);
  $slug=$d['slug']?:preg_replace('/[^a-z0-9]+/','-',mb_strtolower(trim($title),'UTF-8'));
  $slug=trim($slug,'-')?:'post';
  $check=$db->prepare("SELECT COUNT(*)FROM posts WHERE slug=? AND id!=?");
  $check->execute([$slug,$id]);
  if($check->fetchColumn()>0)$slug.='-'.time();
  $excerpt=mb_substr(strip_tags(md($content)),0,200);
  $catIds=array_values(array_unique(array_filter(array_map('intval',(array)($d['categories']??[])))));
  if($catIds)$cat=intval($catIds[0]);

  if($id>0){
    if($createdAt !== ''){
    $db->prepare("UPDATE posts SET title=?,slug=?,content=?,excerpt=?,category_id=?,published=?,created_at=?,publish_at=?,password=?,deleted_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
         ->execute([$title,$slug,$content,$excerpt,$cat,$pub,$createdAt,$publishAtDb,$passwordHash,$id]);
    } else {
    $db->prepare("UPDATE posts SET title=?,slug=?,content=?,excerpt=?,category_id=?,published=?,publish_at=?,password=?,deleted_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
         ->execute([$title,$slug,$content,$excerpt,$cat,$pub,$publishAtDb,$passwordHash,$id]);
    }
  } else {
    if($createdAt !== ''){
      $db->prepare("INSERT INTO posts(title,slug,content,excerpt,category_id,published,created_at,publish_at,password) VALUES(?,?,?,?,?,?,?,?,?)")
         ->execute([$title,$slug,$content,$excerpt,$cat,$pub,$createdAt,$publishAtDb,$passwordHash]);
    } else {
      $db->prepare("INSERT INTO posts(title,slug,content,excerpt,category_id,published,publish_at,password) VALUES(?,?,?,?,?,?,?,?)")
         ->execute([$title,$slug,$content,$excerpt,$cat,$pub,$publishAtDb,$passwordHash]);
    }
    $id=$db->lastInsertId();
  }
  savePostTags($db,$id,$tagsStr);
  $db->prepare("DELETE FROM post_categories WHERE post_id=?")->execute([$id]);
  $pcIns=$db->prepare("INSERT OR IGNORE INTO post_categories(post_id,category_id)VALUES(?,?)");
  foreach($catIds as $cid)$pcIns->execute([$id,$cid]);
  if($cat>0&&!in_array($cat,$catIds,true))$pcIns->execute([$id,$cat]);
  if($pub==1&&(!$publishAtDb||$publishAtDb<=$now)){
    $chk=$db->prepare("SELECT notified FROM posts WHERE id=?");$chk->execute([$id]);
    if(!$chk->fetchColumn()){
      $np=$db->prepare("SELECT id,title,slug,excerpt FROM posts WHERE id=?");$np->execute([$id]);$np=$np->fetch(PDO::FETCH_ASSOC);
      notifyPublish($db,$np);$db->prepare("UPDATE posts SET notified=1 WHERE id=?")->execute([$id]);
    }
  }
  addLog($db,'post_save',$id?'编辑文章 #'.$id:'新建文章 #'.$id);
  regenerateSitemapFile($db);
  json(['ok'=>true,'id'=>$id,'slug'=>$slug]);
}
if($action==='admin_delete'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);
  $db->prepare("UPDATE posts SET deleted_at=? WHERE id=?")->execute([$now,$id]);
  addLog($db,'post_delete','删除文章 #'.$id);
  regenerateSitemapFile($db);
  json(['ok'=>true]);
}
if($action==='admin_trash'&&isAdmin()){
  csrf_verify();
  $posts=$db->query("SELECT id,title,slug,created_at,deleted_at FROM posts WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
  json(['posts'=>$posts]);
}
if($action==='admin_restore_post'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);$db->prepare("UPDATE posts SET deleted_at=NULL WHERE id=?")->execute([$id]);addLog($db,'post_restore','恢复文章 #'.$id);regenerateSitemapFile($db);json(['ok'=>true]);
}
if($action==='admin_purge_post'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);
  $db->prepare("DELETE FROM posts WHERE id=?")->execute([$id]);
  $db->prepare("DELETE FROM post_tags WHERE post_id=?")->execute([$id]);
  $db->prepare("DELETE FROM comments WHERE post_id=?")->execute([$id]);
  addLog($db,'post_purge','永久删除文章 #'.$id);regenerateSitemapFile($db);json(['ok'=>true]);
}
if($action==='admin_batch'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $op=trim($d['op']??'');$ids=array_values(array_map('intval',$d['ids']??[]));$ids=array_filter($ids);
  if(!$ids||!$op)json(['error'=>'参数错误'],400);
  $ph=implode(',',array_fill(0,count($ids),'?'));
  if($op==='publish')$db->prepare("UPDATE posts SET published=1,deleted_at=NULL WHERE id IN ($ph)")->execute($ids);
  elseif($op==='draft')$db->prepare("UPDATE posts SET published=0 WHERE id IN ($ph)")->execute($ids);
  elseif($op==='delete')$db->prepare("UPDATE posts SET deleted_at=? WHERE id IN ($ph)")->execute(array_merge([$now],$ids));
  elseif($op==='restore')$db->prepare("UPDATE posts SET deleted_at=NULL WHERE id IN ($ph)")->execute($ids);
  elseif($op==='category'){$cat=intval($d['value']??0);$db->prepare("UPDATE posts SET category_id=? WHERE id IN ($ph)")->execute(array_merge([$cat],$ids));}
  elseif($op==='tag'){$tag=trim($d['value']??'');foreach($ids as $pid){$old=$db->prepare("SELECT name FROM tags t JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=?");$old->execute([$pid]);savePostTags($db,$pid,implode(',',array_merge($old->fetchAll(PDO::FETCH_COLUMN),[$tag])));}}
  elseif($op==='remove_tag'){
    $tag=trim($d['value']??'');if($tag==='')json(['error'=>'请输入要移除的标签'],400);
    $st=$db->prepare("SELECT id FROM tags WHERE LOWER(name)=LOWER(?) OR LOWER(slug)=LOWER(?) LIMIT 1");$st->execute([$tag,$tag]);$tid=$st->fetchColumn();
    if($tid){$db->prepare("DELETE FROM post_tags WHERE post_id IN ($ph) AND tag_id=?")->execute(array_merge($ids,[$tid]));$db->prepare("DELETE FROM tags WHERE id=? AND NOT EXISTS(SELECT 1 FROM post_tags WHERE tag_id=?)")->execute([$tid,$tid]);}
  }
  else json(['error'=>'不支持的操作'],400);
  addLog($db,'batch_'.$op,'批量操作 '.count($ids).' 篇文章');
  regenerateSitemapFile($db);
  json(['ok'=>true,'count'=>count($ids)]);
}
if($action==='admin_remove_tag_global'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $tag=trim($d['tag']??'');if($tag==='')json(['error'=>'请输入标签名'],400);
  $st=$db->prepare("SELECT id FROM tags WHERE LOWER(name)=LOWER(?) OR LOWER(slug)=LOWER(?) LIMIT 1");$st->execute([$tag,$tag]);$tid=$st->fetchColumn();
  $removed=0;
  if($tid){
    $q=$db->prepare("SELECT COUNT(*) FROM post_tags WHERE tag_id=?");$q->execute([$tid]);$removed=(int)$q->fetchColumn();
    $db->prepare("DELETE FROM post_tags WHERE tag_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM tags WHERE id=?")->execute([$tid]);
  }
  addLog($db,'tag_remove_global','全局移除标签 '.$tag.($removed?'（'.$removed.' 篇文章）':''));
  regenerateSitemapFile($db);
  json(['ok'=>true,'removed'=>$removed]);
}
if($action==='admin_get_post'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);$stmt=$db->prepare("SELECT * FROM posts WHERE id=?");$stmt->execute([$id]);$post=$stmt->fetch(PDO::FETCH_ASSOC);
  if($post){$tagsMap=loadPostTags($db,[$id]);$post['tags']=array_map(function($t){return $t['name'];},$tagsMap[$id]??[]);$post['categories']=array_map(function($x){return $x['id'];},loadPostCats($db,$id));$post['password_set']=!empty($post['password']);unset($post['password']);}
  json($post?:['error'=>'不存在']);
}
if($action==='admin_check_pw'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);$pwd=(string)($_GET['pwd']??'');
  $q=$db->prepare("SELECT password FROM posts WHERE id=?");$q->execute([$id]);$hash=$q->fetchColumn()?:'';
  $match=$hash!==''&&(password_verify($pwd,$hash)||(strpos($hash,'$2')!==0&&hash_equals($hash,$pwd)));
  json(['match'=>!!$match]);
}
if($action==='admin_common_pw'&&isAdmin()){
  csrf_verify();
  $list=json_decode(setting($db,'common_passwords','[]'),true);
  json(['list'=>is_array($list)?$list:[]]);
}
if($action==='admin_common_pw_add'&&isAdmin()){
  csrf_verify();
  $pwd=trim($_GET['pwd']??'');
  if($pwd==='')json(['error'=>'密码不能为空'],400);
  $list=json_decode(setting($db,'common_passwords','[]'),true);
  if(!is_array($list))$list=[];
  if(!in_array($pwd,$list,true)){array_unshift($list,$pwd);$list=array_slice($list,0,10);}
  $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES('common_passwords',?)")->execute([json_encode($list,JSON_UNESCAPED_UNICODE)]);
  json(['ok'=>true,'list'=>$list]);
}
if($action==='admin_cats'&&isAdmin()){
  csrf_verify();
  $stmt=$db->query("SELECT c.*,(SELECT COUNT(*)FROM post_categories pc WHERE pc.category_id=c.id)as post_count FROM categories c ORDER BY c.sort_order,c.name");json($stmt->fetchAll(PDO::FETCH_ASSOC));
}
if($action==='admin_cat_save'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);$id=intval($d['id']??0);$name=trim($d['name']??'');$desc=trim($d['description']??'');$pid=intval($d['parent_id']??0);
  if(!$name)json(['error'=>'分类名称不能为空'],400);$slug=preg_replace('/[^a-z0-9]+/','-',mb_strtolower($name,'UTF-8'));$slug=trim($slug,'-');
  if($slug==='')$slug='cat-'.substr(md5($name.time()),0,8);
  if($pid===$id&&$id>0)json(['error'=>'不能将分类设为自身的子分类'],400);
  if($id>0){
    $db->prepare("UPDATE categories SET name=?,slug=?,description=?,parent_id=? WHERE id=?")->execute([$name,$slug,$desc,$pid,$id]);
    if($db->errorInfo()[2])json(['error'=>'数据库错误: '.$db->errorInfo()[2]],500);
  }else{
    try{$db->prepare("INSERT INTO categories(name,slug,description,parent_id)VALUES(?,?,?,?)")->execute([$name,$slug,$desc,$pid]);}catch(Exception $e){json(['error'=>'保存失败: '.$e->getMessage()],500);}
  }regenerateSitemapFile($db);json(['ok'=>true]);
}
if($action==='admin_cat_delete'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);$db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);$db->prepare("UPDATE posts SET category_id=NULL WHERE category_id=?")->execute([$id]);regenerateSitemapFile($db);json(['ok'=>true]);
}
if($action==='admin_comments'&&isAdmin()){
  csrf_verify();
  $stmt=$db->query("SELECT c.*,p.title as post_title FROM comments c LEFT JOIN posts p ON c.post_id=p.id ORDER BY c.created_at DESC");
  json(['comments'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'blacklist'=>$db->query("SELECT ip,note,created_at FROM comment_blacklist ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC)]);
}
if($action==='admin_blacklist_add'&&isAdmin()){
  csrf_verify();
  $ip=trim($_GET['ip']??'');$note=trim($_GET['note']??'');
  if(!filter_var($ip,FILTER_VALIDATE_IP))json(['error'=>'IP 格式不正确'],400);
  $db->prepare("INSERT OR REPLACE INTO comment_blacklist(ip,note)VALUES(?,?)")->execute([$ip,$note]);
  addLog($db,'blacklist_add','拉黑 '.$ip);
  json(['ok'=>true]);
}
if($action==='admin_blacklist_remove'&&isAdmin()){
  csrf_verify();
  $ip=trim($_GET['ip']??'');$db->prepare("DELETE FROM comment_blacklist WHERE ip=?")->execute([$ip]);json(['ok'=>true]);
}
if($action==='admin_comment_approve'&&isAdmin()){csrf_verify();$id=intval($_GET['id']??0);$db->prepare("UPDATE comments SET approved=1 WHERE id=?")->execute([$id]);json(['ok'=>true]);}
if($action==='admin_comment_delete'&&isAdmin()){csrf_verify();$id=intval($_GET['id']??0);$db->prepare("DELETE FROM comments WHERE id=?")->execute([$id]);json(['ok'=>true]);}
if($action==='admin_upload'&&isAdmin()){
  csrf_verify();
  if(!isset($_FILES['file']))json(['error'=>'未选择文件'],400);
  $f=$_FILES['file'];$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
  $type=$_GET['type']??'image';
  $asAvatar=($_GET['as']??'')==='avatar';
  $allowedExts=uploadAllowedExts($db);
  if(!in_array($ext,$allowedExts))json(['error'=>'不允许的文件类型：'.$ext],400);
  $size=intval($f['size']??0);
  $maxBytes=uploadMaxBytes($db);
  if($size > $maxBytes)json(['error'=>'文件不能超过'.round($maxBytes/1048576).'MB'],400);
  if($size<=0)json(['error'=>'文件内容为空'],400);
  $finfo=new finfo(FILEINFO_MIME_TYPE);
  $mime=$finfo->file($f['tmp_name']);
  $imgMimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','ico'=>'image/x-icon'];
  if($type==='image'&&isset($imgMimes[$ext])&&$mime!==$imgMimes[$ext])json(['error'=>'文件类型不匹配，已拦截'],400);
  $name=time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
  if(!move_uploaded_file($f['tmp_name'],UPLOAD_DIR.'/'.$name))json(['error'=>'文件保存失败'],500);
  if($type==='image'&&in_array($ext,['jpg','jpeg','png','webp','bmp'])&&function_exists('imagecreatefromjpeg')&&function_exists('imagewebp')){
    $src=null;
    if($ext==='png'&&function_exists('imagecreatefrompng'))$src=@imagecreatefrompng(UPLOAD_DIR.'/'.$name);
    elseif($ext==='webp'&&function_exists('imagecreatefromwebp'))$src=@imagecreatefromwebp(UPLOAD_DIR.'/'.$name);
    elseif(function_exists('imagecreatefromjpeg'))$src=@imagecreatefromjpeg(UPLOAD_DIR.'/'.$name);
    if($src){
      $w=imagesx($src);$h=imagesy($src);
      if($w>1920){
        $nh=max(1,intval($h*1920/$w));$dst=imagecreatetruecolor(1920,$nh);imagecopyresampled($dst,$src,0,0,0,0,1920,$nh,$w,$h);imagedestroy($src);$src=$dst;
      }
      $webpName=time().'_'.bin2hex(random_bytes(4)).'.webp';
      if(imagewebp($src,UPLOAD_DIR.'/'.$webpName,82)){imagedestroy($src);@unlink(UPLOAD_DIR.'/'.$name);$name=$webpName;}
      else imagedestroy($src);
    }
  }
  if($type==='image'&&preg_match('/\.webp$/i',$name)&&function_exists('imagecreatefromwebp')){
    $src=@imagecreatefromwebp(UPLOAD_DIR.'/'.$name);
    if($src){
      $w=imagesx($src);$h=imagesy($src);$tw=min(400,$w);$th=max(1,intval($h*$tw/$w));
      $dst=imagecreatetruecolor($tw,$th);imagecopyresampled($dst,$src,0,0,0,0,$tw,$th,$w,$h);
      $thumbName='thumb_'.$name;imagewebp($dst,UPLOAD_DIR.'/'.$thumbName,80);
      imagedestroy($src);imagedestroy($dst);
    }
  }
  if($asAvatar){
    $fixedName=(preg_match('/\.webp$/i',$name))?'avatar.webp':'avatar.'.$ext;
    @unlink(UPLOAD_DIR.'/'.$fixedName);
    @unlink(UPLOAD_DIR.'/thumb_'.$name);
    if(!@rename(UPLOAD_DIR.'/'.$name,UPLOAD_DIR.'/'.$fixedName))$fixedName=$name;
    $old=trim(setting($db,'author_avatar'));
    if($old!==''&&preg_match('#uploads/([A-Za-z0-9_\-\.]+)#',$old,$om)){
      $ofn=basename($om[1]);
      if($ofn!==$fixedName&&is_file(UPLOAD_DIR.'/'.$ofn))@unlink(UPLOAD_DIR.'/'.$ofn);
    }
    $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES('author_avatar',?)")->execute(['./uploads/'.$fixedName]);
    addLog($db,'avatar_update','更新站点头像');
    json(['success'=>1,'url'=>'./uploads/'.$fixedName,'message'=>'头像已更新']);
  }
  if($type==='image'){json(['success'=>1,'url'=>'./uploads/'.$name,'message'=>'上传成功']);}
  else{json(['url'=>'./uploads/'.$name,'name'=>$f['name']]);}
}
if($action==='process_paste'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $html=$d['html']??'';
  $baseUrl=$d['base_url']??'';
  $baseOrigin='';$baseDir='';
  if($baseUrl){$p=parse_url($baseUrl);$baseOrigin=($p['scheme']??'http').'://'.($p['host']??'');$baseDir=dirname($baseUrl);}
  $html=preg_replace_callback('/<noscript>(<img[^>]+>)<\/noscript>/is',function($m){return $m[1];},$html);
  $html=preg_replace_callback('/<picture[^>]*>.*?<source[^>]+srcset\s*=\s*["\'](https?:\/\/[^"\'\s]+)[^"\']*["\'][^>]*>.*?<\/picture>/is',function($m){
    $urls=explode(',',$m[1]);$best=trim(preg_split('/\s+/',trim(end($urls)))[0]);
    return '<img src="'.$best.'" alt="image">';
  },$html);
  $html=preg_replace_callback('/<img([^>]+)srcset\s*=\s*["\']([^"\']+)["\']([^>]*)>/i',function($m){
    $urls=explode(',',$m[2]);$best='';
    foreach($urls as $u){$u=trim($u);if(preg_match('/^(https?:\/\/\S+)/',$u,$um))$best=$um[1];}
    if($best&&preg_match('/src\s*=\s*["\'](data:|[^"\']*?(?:loading|placeholder|blank|transparent|1x1|spacer)[^"\']*?)["\']/i',$m[0]))
      return '<img'.$m[1].'src="'.$best.'"'.$m[3].'>';
    return $m[0];
  },$html);
  $lazyAttrs=['data-src','data-lazy-src','data-original','data-image','data-url','data-actualsrc','data-img','data-hi-res-src'];
  foreach($lazyAttrs as $attr){
    $html=preg_replace_callback('/<img([^>]+)'.$attr.'\s*=\s*["\'](https?:\/\/[^"\']+)["\']([^>]*)>/i',function($m)use($attr){
      $tag='<img'.$m[1].$m[2].'>';
      $tag=preg_replace('/'.$attr.'\s*=\s*["\'][^"\']*["\']/i','',$tag);
      if(preg_match('/src\s*=\s*["\']https?:\/\//',$tag))return $tag;
      $tag=preg_replace('/src\s*=\s*["\'][^"\']*["\']/i','src="'.$m[2].'"',$tag);
      if(!preg_match('/src\s*=/',$tag))$tag=str_replace('<img','<img src="'.$m[2].'"',$tag);
      return $tag;
    },$html);
  }
  if($baseUrl)$html=preg_replace_callback('/<img[^>]+src\s*=\s*["\']((?![a-z]+:|\/\/|data:)[^"\']+)["\'][^>]*>/i',function($m)use($baseDir){return str_replace($m[1],rtrim($baseDir,'/').'/'.ltrim($m[1],'/'),$m[0]);},$html);
  $html=preg_replace('/<img[^>]+src\s*=\s*["\']data:image\/[^;]+;base64,[A-Za-z0-9+\/=]{1,100}["\'][^>]*>/i','',$html);
  $html=preg_replace_callback('/<img[^>]+src\s*=\s*["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',function($m){
    $name=fetchRemoteImage($m[1]);
    if($name)return '<img src="./uploads/'.$name.'" alt="image" style="max-width:100%;border-radius:8px;margin:8px 0">';
    return $m[0];
  },$html);
  $html=preg_replace_callback('/<a[^>]+href\s*=\s*["\']https?:\/\/[^"\']+["\'][^>]*>(<img[^>]+src\s*=\s*["\']\.\/uploads\/[^"\']+["\'][^>]*>)<\/a>/is',function($m){
    if(preg_match('/src\s*=\s*["\'](\.\/uploads\/[^"\']+)["\']/',$m[1],$im))
      return '<a href="'.$im[1].'" target="_blank">'.$m[1].'</a>';
    return $m[1];
  },$html);
  $markdown=$html;
  $markdown=preg_replace_callback('/<pre><code[^>]*>(.*?)<\/code><\/pre>/is',function($m){
    $code=html_entity_decode(strip_tags($m[1]),ENT_QUOTES|ENT_HTML5);
    $code=preg_replace('/<br\s*\/?>/i',"\n",$code);
    $lang='';if(preg_match('/class=["\'].*?language-(\w+)/i',$m[0],$ml))$lang=$ml[1];
    return "\n\n```".$lang."\n".trim($code)."\n```\n\n";
  },$markdown);
  $markdown=preg_replace('/<code>(.*?)<\/code>/is','`$1`',$markdown);
  $markdown=preg_replace('/<h1[^>]*>(.*?)<\/h1>/is',"# $1\n\n",$markdown);
  $markdown=preg_replace('/<h2[^>]*>(.*?)<\/h2>/is',"## $1\n\n",$markdown);
  $markdown=preg_replace('/<h3[^>]*>(.*?)<\/h3>/is',"### $1\n\n",$markdown);
  $markdown=preg_replace('/<h4[^>]*>(.*?)<\/h4>/is',"#### $1\n\n",$markdown);
  $markdown=preg_replace('/<h5[^>]*>(.*?)<\/h5>/is',"##### $1\n\n",$markdown);
  $markdown=preg_replace('/<h6[^>]*>(.*?)<\/h6>/is',"###### $1\n\n",$markdown);
  $markdown=preg_replace('/<strong>(.*?)<\/strong>/is','**$1**',$markdown);
  $markdown=preg_replace('/<b>(.*?)<\/b>/is','**$1**',$markdown);
  $markdown=preg_replace('/<em>(.*?)<\/em>/is','*$1*',$markdown);
  $markdown=preg_replace('/<i>(.*?)<\/i>/is','*$1*',$markdown);
  $markdown=preg_replace('/<u>(.*?)<\/u>/is','<u>$1</u>',$markdown);
  $markdown=preg_replace('/<s>(.*?)<\/s>/is','~~$1~~',$markdown);
  $markdown=preg_replace('/<a[^>]+href=["\'](.*?)["\'][^>]*>(.*?)<\/a>/is','[$2]($1)',$markdown);
  $markdown=preg_replace('/<img[^>]+src=["\'](.*?)["\'][^>]*>/is','![]($1)',$markdown);
  $markdown=preg_replace('/<li>(.*?)<\/li>/is',"- $1\n",$markdown);
  $markdown=preg_replace('/<ol[^>]*>(.*?)<\/ol>/is',"$1\n",$markdown);
  $markdown=preg_replace('/<ul[^>]*>(.*?)<\/ul>/is',"$1\n",$markdown);
  $markdown=preg_replace('/<blockquote>(.*?)<\/blockquote>/is',"> $1\n\n",$markdown);
  $markdown=preg_replace('/<hr[^>]*>/i',"\n---\n",$markdown);
  $markdown=preg_replace('/<br\s*\/?>/i',"\n",$markdown);
  $markdown=preg_replace('/<p[^>]*>(.*?)<\/p>/is',"$1\n\n",$markdown);
  $markdown=preg_replace('/<div[^>]*>(.*?)<\/div>/is',"$1\n",$markdown);
  $markdown=preg_replace('/<span[^>]*>(.*?)<\/span>/is','$1',$markdown);
  $markdown=htmlTableToMarkdown($markdown);
  $markdown=html_entity_decode($markdown,ENT_QUOTES|ENT_HTML5);
  $markdown=strip_tags($markdown);
  $markdown=preg_replace('/\n{4,}/',"\n\n",trim($markdown));
  json(['html'=>$html,'markdown'=>$markdown]);
}
if($action==='render_md'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  json(['html'=>md($d['markdown']??'')]);
}
if($action==='admin_set_theme'&&isAdmin()){
  csrf_verify();
  $th=$_GET['theme']??'';
  if(in_array($th,['blue','warm','light','dark'],true)){
    $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES('site_theme',?)")->execute([$th]);
    addLog($db,'settings','切换主题为 '.$th);
    json(['ok'=>true]);
  }
  json(['error'=>'无效主题'],400);
}
if($action==='admin_import_urls'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $urls=array_values(array_unique(array_filter(array_map('trim',(array)($d['urls']??[])))));
  if(!$urls)json(['error'=>'请提供文章链接'],400);
  $urls=array_slice($urls,0,10);
  $results=[];
  foreach($urls as $url){
    $res=['url'=>$url,'ok'=>false];
    if(!preg_match('#^https?://#i',$url)){$res['error']='链接格式不正确';$results[]=$res;continue;}
    $html=fetchPageHtml($url);
    if($html===null){$res['error']='抓取失败或页面不可访问';$results[]=$res;continue;}
    $title=extractPageTitle($html,$url);
    $title=str_ireplace(' - '.setting($db,'site_name',SITE_NAME),'',$title);
    $md=htmlToMarkdown(extractMainHtml($html));
    if(mb_strlen($md)<40&&mb_strlen($title)<3){$res['error']='未能提取到正文内容';$results[]=$res;continue;}
    $md=absolutizeMarkdownImages($md,$url);
    $md=downloadRemoteImages($md,$db);
    $slug=makeUniqueSlug($db,$title);
    $plain=preg_replace('/[#>*`\[\]!|~-]/u',' ',strip_tags($md));
    $excerpt=mb_substr(trim(preg_replace('/\s+/u',' ',$plain)),0,200);
    $md.="\n\n---\n\n🔗 原文链接：[".$url."](".$url.")";
    $now=date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO posts(title,slug,content,excerpt,category_id,published,created_at,updated_at,notified)VALUES(?,?,?,?,0,0,?,?,1)")->execute([$title,$slug,$md,$excerpt,$now,$now]);
    $pid=(int)$db->lastInsertId();
    addLog($db,'import','导入文章草稿：'.$title);
    $res['ok']=true;$res['title']=$title;$res['id']=$pid;$res['slug']=$slug;
    $results[]=$res;
  }
  regenerateSitemapFile($db);
  json(['ok'=>true,'results'=>$results]);
}
if($action==='admin_settings'&&isAdmin()){
  csrf_verify();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $d=json_decode(file_get_contents('php://input'),true);$stmt=$db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES(?,?)");
    foreach(['site_name','site_desc','site_copyright','site_font','author_name','author_bio','author_avatar','notify_email','notify_webhook','comment_keywords','telegram_bot','telegram_chat','backup_dir'] as $k)if(isset($d[$k]))$stmt->execute([$k,trim($d[$k])]);
    if(isset($d['upload_max_mb'])){
      $mb=intval($d['upload_max_mb']);if($mb<0)$mb=0;if($mb>10240)$mb=10240;
      $stmt->execute(['upload_max_mb',$mb]);
    }
    if(isset($d['upload_exts'])){
      $list=[];$parts=preg_split('/[,，\s]+/',strtolower(trim($d['upload_exts'])));
      foreach($parts as $p){$p=trim($p,'.');if(preg_match('/^[a-z0-9]{1,10}$/',$p)&&!in_array($p,$list,true))$list[]=$p;}
      $stmt->execute(['upload_exts',implode(',',$list)]);
    }
    if(!empty($d['new_pass']))$stmt->execute(['admin_pass',password_hash($d['new_pass'],PASSWORD_BCRYPT)]);
    json(['ok'=>true]);
  }
  $sn=setting($db,'site_name',SITE_NAME);
  $sd=setting($db,'site_desc');
  $sc=setting($db,'site_copyright','Powered by <strong><a href="https://github.com/amw1933/miniblog-single" target="_blank" rel="noopener" style="color:var(--b)">MiniBlog</a></strong> &copy; '.date('Y').' · <a href="https://github.com/amw1933/miniblog-single" target="_blank" rel="noopener" style="color:var(--b)">GitHub</a>');
  $ne=setting($db,'notify_email');
  $nw=setting($db,'notify_webhook');
  $ck=setting($db,'comment_keywords');
  $tb=setting($db,'telegram_bot');
  $tc=setting($db,'telegram_chat');
  $bd=setting($db,'backup_dir');
  $upMb=intval(setting($db,'upload_max_mb',UPLOAD_MAX_SIZE/1048576));
  $upExts=trim(setting($db,'upload_exts',''));
  if($upExts==='')$upExts=implode(',',uploadDefaultExts());
  $backupOk=true;$backupErr='';
  if($bd!==''){
    $custom=$bd;
    if(!preg_match('#^(/|[A-Za-z]:[\\/]|\\\\)#',$custom))$custom=__DIR__.'/'.$custom;
    if(!is_dir($custom)){
      if(!@mkdir($custom,0755,true)){$backupOk=false;$backupErr='目录不存在且无法自动创建，请先在 NAS 上创建并授权';}
    }elseif(!is_writable($custom)){
      $backupOk=false;$backupErr='目录存在但 PHP 没有写权限';
    }
  }
  json(['site_name'=>$sn,'site_desc'=>$sd,'site_copyright'=>$sc,'site_font'=>setting($db,'site_font','great'),'author_name'=>setting($db,'author_name'),'author_bio'=>setting($db,'author_bio'),'author_avatar'=>setting($db,'author_avatar'),'notify_email'=>$ne,'notify_webhook'=>$nw,'comment_keywords'=>$ck,'telegram_bot'=>$tb,'telegram_chat'=>$tc,'backup_dir'=>$bd,'upload_max_mb'=>$upMb,'upload_exts'=>$upExts,'backup_path'=>backupRoot(),'backup_dir_ok'=>$backupOk,'backup_dir_err'=>$backupErr]);
}
if($action==='admin_stats'&&isAdmin()){
  csrf_verify();
  $top=$db->query("SELECT slug,title,views FROM posts WHERE published=1 AND deleted_at IS NULL ORDER BY views DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
  $map=[];$stmt=$db->prepare("SELECT date,pv,uv FROM stats_daily WHERE date>=? ORDER BY date");$stmt->execute([date('Y-m-d',strtotime('-13 days'))]);
  foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)$map[$r['date']]=$r;
  $pmap=[];$pStmt=$db->prepare("SELECT date(created_at) d,COUNT(*) c FROM posts WHERE created_at>=? GROUP BY d");$pStmt->execute([date('Y-m-d',strtotime('-13 days')).' 00:00:00']);
  foreach($pStmt->fetchAll(PDO::FETCH_ASSOC) as $r)$pmap[$r['d']]=$r['c'];
  $daily=[];
  for($i=13;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$daily[]=['date'=>$d,'pv'=>intval($map[$d]['pv']??0),'uv'=>intval($map[$d]['uv']??0),'posts'=>intval($pmap[$d]??0)];}
  $totalVisitors=(int)$db->query("SELECT COALESCE(SUM(uv),0) FROM stats_daily")->fetchColumn();
  $tvStmt=$db->prepare("SELECT uv FROM stats_daily WHERE date=?");$tvStmt->execute([date('Y-m-d')]);
  $todayVisitors=(int)$tvStmt->fetchColumn();
  $totalIps=(int)$db->query("SELECT COUNT(DISTINCT ip) FROM visit_ips")->fetchColumn();
  $tiStmt=$db->prepare("SELECT COUNT(*) FROM visit_ips WHERE day=?");$tiStmt->execute([date('Y-m-d')]);
  $todayIps=(int)$tiStmt->fetchColumn();
  $recentIps=$db->query("SELECT ip,SUM(visits) visits,MAX(last_at) last_at,MAX(ua) ua FROM visit_ips GROUP BY ip ORDER BY last_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
  json(['posts'=>$db->query("SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL")->fetchColumn(),'published'=>$db->query("SELECT COUNT(*) FROM posts WHERE published=1 AND deleted_at IS NULL")->fetchColumn(),'comments'=>$db->query("SELECT COUNT(*) FROM comments")->fetchColumn(),'pending'=>$db->query("SELECT COUNT(*) FROM comments WHERE approved=0")->fetchColumn(),'views'=>$db->query("SELECT COALESCE(SUM(views),0) FROM posts WHERE deleted_at IS NULL")->fetchColumn(),'visitors'=>$totalVisitors,'today_visitors'=>$todayVisitors,'total_ips'=>$totalIps,'today_ips'=>$todayIps,'recent_ips'=>$recentIps,'top'=>$top,'daily'=>$daily]);
}
function uploadRefNames($db){
  $refs=[];
  $rows=$db->query("SELECT content FROM posts")->fetchAll(PDO::FETCH_COLUMN);
  foreach($rows as $c)if(preg_match_all('/(?<![A-Za-z0-9_\-])uploads\/([^\s"\'<>()]+)/',$c,$mm))$refs=array_merge($refs,$mm[1]);
  $sv=$db->query("SELECT value FROM settings")->fetchAll(PDO::FETCH_COLUMN);
  foreach($sv as $v)if($v!==''&&preg_match_all('/(?<![A-Za-z0-9_\-])uploads\/([^\s"\'<>()]+)/',$v,$mm))$refs=array_merge($refs,$mm[1]);
  $refs=array_flip(array_unique($refs));
  $refs['avatar.webp']=1;
  return $refs;
}
function lockedFileNames($db){
  $l=json_decode(setting($db,'locked_files','[]'),true);
  return is_array($l)?array_values(array_filter(array_map('trim',$l))):[];
}
if($action==='admin_cleanup_uploads'&&isAdmin()){
  csrf_verify();
  $refs=uploadRefNames($db);foreach(lockedFileNames($db) as $fn)$refs[$fn]=1;
  $deleted=[];$kept=0;$files=@scandir(UPLOAD_DIR);
  if($files===false)$files=[];
  foreach($files as $fn){
    if($fn==='.'||$fn==='..'||strpos($fn,'.')===0)continue;
    if(preg_match('/^thumbs\.db$/i',$fn))continue;
    if(isset($refs[$fn]))continue;
    $fp=UPLOAD_DIR.'/'.$fn;
    if(!is_file($fp))continue;
    if(@unlink($fp)){$deleted[]=$fn;$db->prepare("DELETE FROM remote_image_cache WHERE local=?")->execute([$fn]);}else{$kept++;}
  }
  addLog($db,'files_cleanup','一键清理 '.count($deleted).' 个未引用文件');
  json(['ok'=>true,'deleted'=>$deleted,'kept'=>$kept]);
}
if($action==='admin_files'&&isAdmin()){
  csrf_verify();
  $refs=uploadRefNames($db);
  $lockedSet=array_flip(lockedFileNames($db));
  $files=[];$total=0;
  $byExt=[];
  $finfo=class_exists('finfo')?new finfo(FILEINFO_MIME_TYPE):null;
  $typeMap=['image/png'=>'png','image/jpeg'=>'jpeg','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg','image/bmp'=>'bmp','image/x-icon'=>'ico','application/zip'=>'zip','application/x-rar'=>'rar','application/x-7z-compressed'=>'7z','application/x-tar'=>'tar','application/gzip'=>'gz','application/pdf'=>'pdf','application/vnd.android.package-archive'=>'apk','application/x-iso9660-image'=>'iso','text/plain'=>'txt','text/markdown'=>'md','text/csv'=>'csv','application/json'=>'json','application/xml'=>'xml','text/html'=>'html','video/mp4'=>'mp4','video/x-msvideo'=>'avi','video/quicktime'=>'mov','audio/mpeg'=>'mp3','audio/mp4'=>'m4a'];
  foreach(@scandir(UPLOAD_DIR)?:[] as $fn){
    if($fn==='.'||$fn==='..'||strpos($fn,'.')===0)continue;
    if(preg_match('/^thumbs\.db$/i',$fn))continue;
    $fp=UPLOAD_DIR.'/'.$fn;if(!is_file($fp))continue;
    $s=filesize($fp);$total+=$s;
    $e=strtolower(pathinfo($fn,PATHINFO_EXTENSION))?:'none';
    $mime=$finfo?($finfo->file($fp)?:''):'';
    $real=($mime===''||$mime==='application/octet-stream')?$e:($typeMap[$mime]??$e);
    $byExt[$real]=($byExt[$real]??0)+$s;
    $files[]=['name'=>$fn,'size'=>$s,'mtime'=>date('Y-m-d H:i:s',filemtime($fp)),'used'=>isset($refs[$fn]),'locked'=>isset($lockedSet[$fn]),'ext'=>$e,'type'=>$real,'mime'=>$mime];
  }
  usort($files,function($a,$b){return strcmp($b['mtime'],$a['mtime']);});
  $maxB=uploadMaxBytes($db);
  json(['files'=>$files,'total'=>$total,'count'=>count($files),'byExt'=>$byExt,'maxBytes'=>$maxB===PHP_INT_MAX?0:$maxB]);
}
if($action==='admin_file_lock'&&isAdmin()){
  csrf_verify();
  $name=basename($_GET['name']??'');
  $lock=intval($_GET['lock']??1)?1:0;
  if($name===''||$name==='.'||$name==='..'||$name==='avatar.webp')json(['error'=>'参数错误'],400);
  $fp=UPLOAD_DIR.'/'.$name;
  if(!is_file($fp))json(['error'=>'文件不存在'],404);
  $list=lockedFileNames($db);
  $key=array_search($name,$list,true);
  if($lock){if($key===false)$list[]=$name;}
  elseif($key!==false)array_splice($list,$key,1);
  $db->prepare("INSERT OR REPLACE INTO settings(key,value)VALUES('locked_files',?)")->execute([json_encode(array_values($list),JSON_UNESCAPED_UNICODE)]);
  addLog($db,$lock?'file_lock':'file_unlock',($lock?'锁定':'解锁').'文件 '.$name);
  json(['ok'=>true,'locked'=>!!$lock]);
}
if($action==='admin_file_delete'&&isAdmin()){
  csrf_verify();
  $name=basename($_GET['name']??'');
  if($name===''||$name==='.'||$name==='..')json(['error'=>'参数错误'],400);
  $refs=uploadRefNames($db);foreach(lockedFileNames($db) as $fn)$refs[$fn]=1;
  if(isset($refs[$name]))json(['error'=>'该文件被引用或已锁定，不允许删除'],403);
  $fp=UPLOAD_DIR.'/'.$name;
  if(!is_file($fp))json(['error'=>'文件不存在'],404);
  if(!@unlink($fp))json(['error'=>'删除失败'],500);
  $db->prepare("DELETE FROM remote_image_cache WHERE local=?")->execute([$name]);
  addLog($db,'file_delete','删除文件 '.$name);
  json(['ok'=>true]);
}
if($action==='admin_files_batch_delete'&&isAdmin()){
  csrf_verify();
  $d=json_decode(file_get_contents('php://input'),true);
  $names=array_values(array_unique(array_filter(array_map(function($n){return basename((string)$n);},$d['files']??[]))));
  if(!$names)json(['error'=>'请选择要删除的文件'],400);
  $refs=uploadRefNames($db);foreach(lockedFileNames($db) as $fn)$refs[$fn]=1;
  $deleted=[];$skipped=[];
  foreach($names as $n){
    if(isset($refs[$n])){$skipped[]=$n;continue;}
    $fp=UPLOAD_DIR.'/'.$n;
    if(is_file($fp)&&@unlink($fp)){$db->prepare("DELETE FROM remote_image_cache WHERE local=?")->execute([$n]);$deleted[]=$n;}
  }
  addLog($db,'files_batch_delete','批量删除 '.count($deleted).' 个未引用文件');
  json(['ok'=>true,'deleted'=>$deleted,'skipped'=>$skipped]);
}
if($action==='admin_backup'&&isAdmin()){
  csrf_verify();
  $snap=createBackupSnapshot($db);
  if(!empty($snap['running']))json(['error'=>'正在备份中，请稍候','running'=>true,'since'=>$snap['since']],409);
  if(!$snap)json(['error'=>'备份创建失败'],500);
  addLog($db,'backup','增量备份 '.$snap['stamp'].'（新增 '.$snap['changed'].' 个文件）');
  json(['ok'=>true,'file'=>$snap['stamp'],'changed'=>$snap['changed'],'total'=>$snap['total'],'full_size'=>$snap['full_size']??0,'full_count'=>$snap['full_count']??0,'root'=>backupRoot()]);
}
if($action==='admin_backup_download'&&isAdmin()){
  csrf_verify();
  $tmp=backupRoot().'/download-'.time().'.zip';
  if(!buildBackupZip($db,$tmp))json(['error'=>'完整备份生成失败'],500);
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="miniblog-full-'.date('Ymd-His').'.zip"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);@unlink($tmp);exit;
}
if($action==='admin_backup_list'&&isAdmin()){
  csrf_verify();
  $dirs=glob(backupRoot().'/snapshots/*',GLOB_ONLYDIR)?:[];$list=[];
  foreach($dirs as $d){
    $size=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f)if($f->isFile())$size+=$f->getSize();
    $list[]=['name'=>basename($d),'size'=>$size,'mtime'=>date('Y-m-d H:i:s',filemtime($d))];
  }
  usort($list,function($a,$b){return strcmp($b['mtime'],$a['mtime']);});
  $root=backupRoot();$manifest=backupManifest(true);
  $fullSize=0;$missing=0;$fullCount=count($manifest['files']??[]);
  foreach($manifest['files']??[] as $info){if(is_file($root.'/'.$info['store']))$fullSize+=intval($info['size']);else $missing++;}
  json(['files'=>$list,'full_size'=>$fullSize,'full_count'=>$fullCount,'complete'=>$missing===0,'missing'=>$missing]);
}
if($action==='admin_backup_delete'&&isAdmin()){
  csrf_verify();
  $name=basename($_GET['file']??'');
  if($name===''||!preg_match('/^\d{8}-\d{6}$/',$name))json(['error'=>'参数错误'],400);
  if(!removeBackupSnapshot($name))json(['error'=>'文件不存在'],404);
  addLog($db,'backup_delete','删除备份 '.$name);
  json(['ok'=>true]);
}
if($action==='admin_backup_test'&&isAdmin()){
  csrf_verify();
  $dir=trim($_GET['dir']??'');
  $dir=$dir!==''?$dir:backupRoot();
  if(!preg_match('#^(/|[A-Za-z]:[\\/]|\\\\)#',$dir))$dir=__DIR__.'/'.$dir;
  $res=['dir'=>$dir,'exists'=>is_dir($dir),'writable'=>is_dir($dir)?is_writable($dir):false,'php_user'=>'','open_basedir'=>ini_get('open_basedir')?:'(不限)','disk_free'=>@disk_free_space($dir)?:0];
  if(function_exists('posix_getpwuid')&&function_exists('posix_geteuid')){$pu=@posix_getpwuid(@posix_geteuid());$res['php_user']=isset($pu['name'])?$pu['name'].' ('.@posix_geteuid().')':'';}
  if(!is_dir($dir)){
    @mkdir($dir,0755,true);
    $res['mkdir_ok']=is_dir($dir);
    $res['mkdir_error']=error_get_last()['message']??'';
    $res['exists']=is_dir($dir);
  }
  if(is_dir($dir)){
    $tf=$dir.'/.miniblog_test_'.time().'.tmp';
    $res['write_test']=@file_put_contents($tf,'ok')!==false;
    if(is_file($tf))@unlink($tf);
    $res['write_error']=error_get_last()['message']??'';
  }
  json($res);
}
if($action==='admin_backup_status'&&isAdmin()){
  csrf_verify();
  $lock=backupRoot().'/backup.lock';
  if(is_file($lock)&&(time()-filemtime($lock))<600)json(['running'=>true,'since'=>date('Y-m-d H:i:s',filemtime($lock))]);
  json(['running'=>false]);
}
if($action==='admin_restore'&&isAdmin()){
  csrf_verify();
  if(empty($_FILES['file']))json(['error'=>'未选择文件'],400);
  $tmp=$_FILES['file']['tmp_name'];$ext=strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION));
  if($ext==='zip'&&class_exists('ZipArchive')){
    $zip=new ZipArchive();
    if($zip->open($tmp)!==true)json(['error'=>'无法打开备份包'],400);
    for($i=0;$i<$zip->numFiles;$i++){
      $n=$zip->getNameIndex($i);
      if(strpos($n,'..')!==false)continue;
      $n=ltrim($n,'/');
      if(!preg_match('#^(index\.php|editormd/|data/|uploads/)#',$n))continue;
      if(preg_match('#^data/backup/#',$n))continue;
      $dest=__DIR__.'/'.$n;
      $dir=dirname($dest);if(!is_dir($dir))@mkdir($dir,0755,true);
      $content=$zip->getFromIndex($i);
      if($content!==false)file_put_contents($dest,$content);
    }
    $zip->close();
  }elseif($ext==='db'||$ext==='sqlite'){
    copy($tmp,DB_FILE);
  }else json(['error'=>'只支持 .zip 备份包或 .db 数据库文件'],400);
  addLog($db,'restore','恢复备份');
  json(['ok'=>true]);
}
if($action==='admin_restore_server'&&isAdmin()){
  csrf_verify();
  $stamp=basename($_GET['file']??'');
  if($stamp===''||!preg_match('/^\d{8}-\d{6}$/',$stamp))json(['error'=>'参数错误'],400);
  $root=backupRoot();
  $snapDir=$root.'/snapshots/'.$stamp;
  if(!is_dir($snapDir))json(['error'=>'快照不存在'],404);
  $tmp=$root.'/download-'.time().'.zip';
  if(!buildBackupZip($db,$tmp))json(['error'=>'无法生成完整备份'],500);
  if(class_exists('ZipArchive')){
    $zip=new ZipArchive();
    if($zip->open($tmp)===true){
      for($i=0;$i<$zip->numFiles;$i++){
        $n=$zip->getNameIndex($i);
        if(strpos($n,'..')!==false)continue;
        $n=ltrim($n,'/');
        if(!preg_match('#^(index\.php|editormd/|data/|uploads/)#',$n))continue;
        if(preg_match('#^data/backup/#',$n))continue;
        $dest=__DIR__.'/'.$n;$dir=dirname($dest);if(!is_dir($dir))@mkdir($dir,0755,true);
        $content=$zip->getFromIndex($i);if($content!==false)file_put_contents($dest,$content);
      }
      $zip->close();
    }
  }
  @unlink($tmp);
  addLog($db,'restore_server','从服务器快照恢复 '.$stamp);
  json(['ok'=>true]);
}
if($action==='admin_logs'&&isAdmin()){
  csrf_verify();
  $rows=$db->query("SELECT id,action,detail,ip,created_at FROM admin_logs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
  json(['logs'=>$rows,'server_now'=>date('Y-m-d H:i:s'),'last_created'=>isset($rows[0]['created_at'])?$rows[0]['created_at']:'']);
}
if($action==='admin_logs_tz_fix'&&isAdmin()){
  csrf_verify();
  $db->exec("UPDATE admin_logs SET created_at=datetime(created_at,'+8 hours') WHERE created_at IS NOT NULL");
  addLog($db,'log_tz_fix','校准日志时间为东八区');
  json(['ok'=>true]);
}
if($action==='admin_logs_delete'&&isAdmin()){
  csrf_verify();
  $id=intval($_GET['id']??0);
  if($id>0){
    $db->prepare("DELETE FROM admin_logs WHERE id=?")->execute([$id]);
    addLog($db,'log_delete','删除日志 #'.$id);
  }else{
    $db->exec("DELETE FROM admin_logs");
  }
  json(['ok'=>true]);
}
if($action==='hot_searches'){
  json($db->query("SELECT keyword,hits FROM search_log ORDER BY hits DESC,last_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
}
if($action==='manifest'){
  $snM=setting($db,'site_name',SITE_NAME);
  header('Content-Type: application/manifest+json');
  echo json_encode(['name'=>$snM,'short_name'=>$snM,'start_url'=>SITE_URL.'/','display'=>'standalone','background_color'=>'#0d1117','theme_color'=>'#2563eb','icons'=>[['src'=>SITE_URL.'/editormd/images/logos/editormd-logo-240x240.png','sizes'=>'240x240','type'=>'image/png'],['src'=>SITE_URL.'/editormd/images/logos/editormd-logo-320x320.png','sizes'=>'320x320','type'=>'image/png']]],JSON_UNESCAPED_UNICODE);exit;
}
if($action==='sw.js'){
  header('Content-Type: application/javascript');
  echo 'const C="miniblog-v2";const ASSETS=["editormd/lib/highlight.min.js","editormd/lib/highlight-github.min.css","editormd/lib/mermaid.min.js"];self.addEventListener("install",e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(ASSETS)).catch(()=>{}));});self.addEventListener("activate",e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==C).map(k=>caches.delete(k)))));});self.addEventListener("fetch",e=>{if(e.request.method!=="GET")return;const u=new URL(e.request.url);if(u.search.includes("admin")||u.search.includes("action="))return;if(/\.(js|css|png|jpe?g|gif|webp|svg|woff2?|ttf|eot|ico)$/i.test(u.pathname)){e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request).then(res=>{const cl=res.clone();caches.open(C).then(c=>c.put(e.request,cl));return res;})));return;}e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)));});';exit;
}

function md($t){
  $blocks=[];$t=preg_replace_callback('/```(\w*)\n(.*?)```/s',function($m)use(&$blocks){$i=count($blocks);$blocks[$i]=['lang'=>$m[1],'code'=>$m[2]];return "␌BLOCK{$i}␌";},$t);
  // 提取视频标记 !video[title](url)
  $videos=[];$t=preg_replace_callback('/!video\[(.*?)\]\((.*?)\)/',function($m)use(&$videos){$i=count($videos);$videos[$i]=['title'=>$m[1],'url'=>$m[2]];return "␌VIDEO{$i}␌";},$t);
  $t=htmlspecialchars($t);
  $t=preg_replace('/\{font:(\d+)\}/','<span style="font-size:$1px">',$t);
  $t=str_replace('{/font}','</span>',$t);
  $t=preg_replace('/\{color:(#[0-9a-fA-F]{3,8})\}/','<span style="color:$1">',$t);
  $t=str_replace('{/color}','</span>',$t);
  $t=preg_replace_callback('/\{tag:([a-zA-Z0-9]+)\}/',function($m){$s=['b','i','u','s','mark','small','sub','sup','code','kbd','strong','em'];return in_array($m[1],$s,true)?'<'.$m[1].'>':'';},$t);
  $t=preg_replace_callback('/\{\/tag:([a-zA-Z0-9]+)\}/',function($m){$s=['b','i','u','s','mark','small','sub','sup','code','kbd','strong','em'];return in_array($m[1],$s,true)?'</'.$m[1].'>':'';},$t);
  $t=preg_replace('/### (.+)/','<h3>$1</h3>',$t);$t=preg_replace('/## (.+)/','<h2>$1</h2>',$t);$t=preg_replace('/# (.+)/','<h1>$1</h1>',$t);
  $t=preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>',$t);$t=preg_replace('/\*(.+?)\*/','<em>$1</em>',$t);$t=preg_replace('/~~(.+?)~~/','<s>$1</s>',$t);
  $t=preg_replace('/`(.+?)`/','<code>$1</code>',$t);
  $t=preg_replace('/!\[(.*?)\]\((.*?)\)/','<img src="$2" alt="$1" style="max-width:100%;border-radius:8px;margin:8px 0">',$t);
  $t=preg_replace('/\[(.*?)\]\((.*?)\)/','<a href="$2" target="_blank" rel="noopener">$1</a>',$t);
  $t=preg_replace('/^\- (.+)/m','<li>$1</li>',$t);$t=preg_replace('/(<li>.*<\/li>)/s','<ul>$1</ul>',$t);
  $t=preg_replace('/^(\d+)\. (.+)/m','<li>$2</li>',$t);
  $t=preg_replace('/^> (.+)/m','<blockquote>$1</blockquote>',$t);
  $t=preg_replace('/^---+\s*$/m','<hr>',$t);
  $t=preg_replace_callback('/(^|\n)((?:\|[^\n]*\|[ \t]*(?:\n|$)){2,})/',function($m){
    $trail=substr($m[2],-1)==="\n"?"\n":"";
    $lines=array_values(array_filter(explode("\n",trim($m[2])),function($x){return trim($x)!=='';}));
    if(count($lines)<2)return $m[0];
    $cells=function($line){$line=trim($line);$line=trim($line,'|');return array_map('trim',explode('|',$line));};
    $rows=array_map($cells,$lines);
    $head=array_shift($rows);$sep=array_shift($rows);
    $isSep=function($r){if(!count($r))return false;foreach($r as $cell){if(!preg_match('/^:?-{2,}:?$/',trim($cell)))return false;}return true;};
    if(!$isSep($sep)){array_unshift($rows,$sep);$sep=null;}
    $h='<table><thead><tr>';
    foreach($head as $c)$h.='<th>'.($c===''?'&nbsp;':$c).'</th>';
    $h.='</tr></thead><tbody>';
    foreach($rows as $r){$h.='<tr>';foreach($r as $c)$h.='<td>'.($c===''?'&nbsp;':$c).'</td>';$h.='</tr>';}
    $h.='</tbody></table>';
    return $m[1].$h.$trail;
  },$t);
  $t=nl2br($t);
  foreach($blocks as $i=>$b)$t=str_replace("␌BLOCK{$i}␌",'<pre><code class="language-'.($b['lang']?$b['lang']:'bash').'">'.htmlspecialchars($b['code']).'</code></pre>',$t);
  $t=preg_replace_callback('/<pre><code[^>]*>(.*?)<\/code><\/pre>/s',function($m){return str_replace(['<br />','<br>'],'',$m[0]);},$t);
  // 恢复视频标记
  foreach($videos as $i=>$v){
    $html='';
    if(preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',$v['url'],$m)){
      $vid=$m[1];
      $html='<div class="video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:12px 0;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.1)"><iframe src="https://www.youtube.com/embed/'.$vid.'" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%"></iframe></div>';
    }else{
      $html='<video controls style="max-width:100%;border-radius:12px;margin:12px 0;box-shadow:0 2px 12px rgba(0,0,0,.1)" poster=""><source src="'.$v['url'].'">您的浏览器不支持视频播放</video>';
    }
    $t=str_replace("␌VIDEO{$i}␌",$html,$t);
  }
  return $t;
}
function mdWithAlt($t,$title){
  $html=md($t);
  $alt=htmlspecialchars(strip_tags($title),ENT_QUOTES);
  return preg_replace('/<img([^>]*)\balt="[^"]*"/i','<img$1 alt="'.$alt.'"',$html);
}

/**
 * 获取文章缩略图：提取第一张图片，无图则生成渐变抽象 SVG
 */
function siteFontStacks(){
  return [
    'web'=>"'MaShanZheng','Segoe Script','Palatino Linotype','Brush Script MT',cursive",
    'great'=>"'GreatVibes','Segoe Script','Palatino Linotype','Brush Script MT',cursive",
    'allura'=>"'Allura','Monotype Corsiva','Palace Script MT','Vivaldi',cursive",
    'dancing'=>"'DancingScript','Comic Sans MS','Chalkboard SE','Marker Felt',cursive",
    'script'=>"'Segoe Script','Palatino Linotype','Brush Script MT',cursive",
    'script2'=>"'Monotype Corsiva','Palace Script MT','Vivaldi',cursive",
    'script3'=>"'Segoe Print','Kristen ITC','Comic Sans MS',cursive",
    'script4'=>"'Comic Sans MS','Chalkboard SE','Marker Felt',cursive",
    'script5'=>"'Lucida Handwriting','Brush Script MT','Segoe Script',cursive",
    'script6'=>"'Edwardian Script ITC','Vladimir Script','French Script MT',cursive",
    'serif'=>"Georgia,'Times New Roman','Songti SC','SimSun',serif",
    'kai'=>"'KaiTi','STKaiti','Kaiti SC','Kai',serif",
    'sans'=>"'Segoe UI','PingFang SC','Microsoft YaHei',system-ui,sans-serif",
  ];
}
function siteFontCss($db){
  $stacks=siteFontStacks();
  $key=trim(setting($db,'site_font','great'));
  return $stacks[$key]??$stacks['great'];
}
function gearIcon($size=16,$color='currentColor'){
  return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
}
function ico($name,$size=15,$color='currentColor'){
  $icons=[
    'home'=>'<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    'calendar'=>'<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'rss'=>'<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>',
    'file-text'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'trash'=>'<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'chart'=>'<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
    'tag'=>'<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'folder'=>'<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'message'=>'<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/>',
    'logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'filter'=>'<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
    'user'=>'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'archive'=>'<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    'upload'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    'clipboard'=>'<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
    'key'=>'<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    'save'=>'<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
    'eye'=>'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'clock'=>'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'lock'=>'<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'search'=>'<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'alert'=>'<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'download'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'flask'=>'<path d="M10 2v6L4.5 19a2 2 0 0 0 1.8 3h11.4a2 2 0 0 0 1.8-3L14 8V2"/><line x1="8.5" y1="2" x2="15.5" y2="2"/><line x1="7" y1="15" x2="17" y2="15"/>',
    'edit'=>'<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>',
    'box'=>'<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
    'sun'=>'<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
    'moon'=>'<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    'flame'=>'<path d="M12 2s5 4 5 9a5 5 0 0 1-10 0c0-5 5-9 5-9z"/><path d="M12 22a5 5 0 0 1-2.5-9.4C10 14 11 15 12 16c1-1 2-2 2.5-3.4A5 5 0 0 1 12 22z"/>',
    'image'=>'<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'paperclip'=>'<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    'video'=>'<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
    'list'=>'<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'bell'=>'<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
  ];
  $inner=$icons[$name]??'';
  if($inner==='')return '';
  return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px" aria-hidden="true">'.$inner.'</svg>';
}
function getPostThumbnail($content,$title){
  global $db;
  // 提取第一张图片（Markdown 或 HTML）
  if(preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\'][^>]*>/i',$content,$m)){
    $src=$m[1];
    if(preg_match('~/uploads/([A-Za-z0-9_\-\.]+)$~',$src,$tm)&&file_exists(UPLOAD_DIR.'/thumb_'.$tm[1]))return './uploads/thumb_'.$tm[1];
    return $src;
  }
  if(preg_match('/!\[.*?\]\(([^)]+)\)/',$content,$m)){
    $src=$m[1];
    if(preg_match('~/uploads/([A-Za-z0-9_\-\.]+)$~',$src,$tm)&&file_exists(UPLOAD_DIR.'/thumb_'.$tm[1]))return './uploads/thumb_'.$tm[1];
    return $src;
  }
  // 无图 → 生成渐变 SVG 缩略图（data URI）
  $hash=abs(crc32($title));
  $palettes=[
    ['#667eea','#764ba2'],['#f093fb','#f5576c'],['#4facfe','#00f2fe'],
    ['#43e97b','#38f9d7'],['#fa709a','#fee140'],['#a18cd1','#fbc2eb'],
    ['#fccb90','#d57eeb'],['#e0c3fc','#8ec5fc'],['#f5576c','#ff9a9e'],
    ['#0c3483','#a2b6df'],['#f2709c','#ff9472'],['#c471f5','#fa71cd'],
    ['#12c2e9','#c471ed'],['#feada6','#f5efef'],['#ffecd2','#fcb69f'],
    ['#13547a','#80d0c7'],['#667db6','#0082c8'],['#b224ef','#7579ff'],
    ['#00b4db','#0083b0'],['#f12711','#f5af19'],['#f7971e','#ffd200'],
    ['#004e92','#000428'],['#348f50','#56ab2f'],['#6a3093','#a044ff'],
    ['#00c6fb','#005bea'],['#b92b27','#1565c0'],['#1e3c72','#2a5298'],
    ['#e65c00','#f9d423'],['#2193b0','#6dd5ed'],['#cc2b5e','#753a88'],
  ];
  static $palOrder=null,$palPos=0;
  if($palOrder===null){$palOrder=array_keys($palettes);shuffle($palOrder);}
  $pal=$palettes[$palOrder[$palPos%count($palOrder)]];$palPos++;
  $gid='g'.bin2hex(random_bytes(5));
  $siteName=setting($db,'site_name',SITE_NAME);
  $siteName=htmlspecialchars($siteName,ENT_QUOTES|ENT_XML1,'UTF-8');
  $svgFont=str_replace("'",'&apos;',siteFontCss($db));
  $nameLen=0;$charCount=0;
  for($i=0;$i<mb_strlen($siteName,'UTF-8');$i++){
    $ch=mb_substr($siteName,$i,1,'UTF-8');
    $charCount++;
    $nameLen+=preg_match('/[\x{4e00}-\x{9fff}]/u',$ch)?1.05:0.68;
  }
  $fontSize=floor(330/max(1,($nameLen+$charCount*0.02)));
  $fontSize=min(46,max(12,$fontSize));
  $letterSpacing=($fontSize<34)?0:max(0,round($fontSize*0.06));
  $fitAttr=($fontSize<30)?' textLength="330" lengthAdjust="spacingAndGlyphs"':'';
  $cx1=80+($hash%200);$cy1=60+($hash%120);$r1=40+($hash%60);
  $cx2=220+($hash%150);$cy2=80+($hash%100);$r2=30+($hash%50);
  $cx3=($hash%300);$cy3=140+($hash%60);$r3=20+($hash%40);
  $svg='<svg xmlns="http://www.w3.org/2000/svg" width="400" height="240" viewBox="0 0 400 240" preserveAspectRatio="xMidYMid slice">
    <defs><linearGradient id="'.$gid.'" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:'.$pal[0].'"/>
      <stop offset="100%" style="stop-color:'.$pal[1].'"/>
    </linearGradient></defs>
    <rect width="400" height="240" fill="url(#'.$gid.')"/>
    <circle cx="'.$cx1.'" cy="'.$cy1.'" r="'.$r1.'" fill="rgba(255,255,255,0.12)"/>
    <circle cx="'.$cx2.'" cy="'.$cy2.'" r="'.$r2.'" fill="rgba(255,255,255,0.08)"/>
    <circle cx="'.$cx3.'" cy="'.$cy3.'" r="'.$r3.'" fill="rgba(255,255,255,0.06)"/>
<circle cx="'.(($hash*7)%350+10).'" cy="'.(($hash*13)%200+20).'" r="'.(($hash*17)%40+15).'" fill="rgba(255,255,255,0.04)"/>
<text x="200" y="172" text-anchor="middle" font-family="'.$svgFont.'" font-size="'.$fontSize.'" font-style="italic" font-weight="600" letter-spacing="'.$letterSpacing.'"'.$fitAttr.' fill="rgba(0,0,0,0.28)">'.$siteName.'</text>
<text x="200" y="168" text-anchor="middle" font-family="'.$svgFont.'" font-size="'.$fontSize.'" font-style="italic" font-weight="600" letter-spacing="'.$letterSpacing.'"'.$fitAttr.' fill="rgba(255,255,255,0.96)">'.$siteName.'</text>
</svg>';
  return $svg;
}

function fetchRemoteImage($url){
  if(!preg_match('#^https?://#i',$url))return null;
  $data=null;$http=0;$cl=0;
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>15,
      CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>1,CURLOPT_SSL_VERIFYHOST=>2,
      CURLOPT_MAXFILESIZE=>REMOTE_IMAGE_MAX_BYTES,
      CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      CURLOPT_REFERER=>'https://www.google.com/',
      CURLOPT_ENCODING=>''
    ]);
    $data=curl_exec($ch);
    $http=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $cl=curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
  }else{
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>15,'follow_location'=>1,'max_redirects'=>5,'header'=>"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"]]);
    $data=@file_get_contents($url,false,$ctx);
    if($data!==false)$http=200;
  }
  if(!$data||$http!=200||$cl>REMOTE_IMAGE_MAX_BYTES)return null;
  if(strlen($data)>REMOTE_IMAGE_MAX_BYTES||strlen($data)<10)return null;
  $finfo=new finfo(FILEINFO_MIME_TYPE);
  $mime=$finfo->buffer($data);
  if(strpos($mime,'image/')!==0)return null;
  $ext=strtolower(pathinfo(parse_url($url,PHP_URL_PATH),PATHINFO_EXTENSION));
  if(!in_array($ext,['jpg','jpeg','png','gif','webp','svg','bmp','ico']))$ext='jpg';
  $name=time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
  if(file_put_contents(UPLOAD_DIR.'/'.$name,$data)===false)return null;
  return $name;
}

function downloadRemoteImages($content,$db){
  $dlCount=0;
  $dlFunc=function($url)use($db,&$dlCount){
    if($dlCount>=REMOTE_IMAGE_MAX_PER_SAVE)return null;
    $stmt=$db->prepare("SELECT local FROM remote_image_cache WHERE url=?");
    $stmt->execute([$url]);$cached=$stmt->fetchColumn();
    if($cached&&file_exists(UPLOAD_DIR.'/'.$cached))return $cached;
    $name=fetchRemoteImage($url);
    if(!$name)return null;
    $db->prepare("INSERT OR REPLACE INTO remote_image_cache(url,local)VALUES(?,?)")->execute([$url,$name]);
    $dlCount++;
    return $name;
  };
  $content=preg_replace_callback('/!\[(.*?)\]\((https?:\/\/[^)\s]+)\)/i',function($m)use($dlFunc){
    $name=$dlFunc($m[2]);if($name)return '!['.$m[1].'](./uploads/'.$name.')';
    return $m[0];
  },$content);
  $content=preg_replace_callback('/<img[^>]+src\s*=\s*["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',function($m)use($dlFunc){
    $name=$dlFunc($m[1]);if($name)return str_replace($m[1],'./uploads/'.$name,$m[0]);
    return $m[0];
  },$content);
  return $content;
}
function fetchPageHtml($url){
  $data=null;$http=0;
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>20,
      CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>1,CURLOPT_SSL_VERIFYHOST=>2,
      CURLOPT_MAXFILESIZE=>3145728,
      CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      CURLOPT_ENCODING=>''
    ]);
    $data=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  }else{
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'follow_location'=>1,'max_redirects'=>5,'header'=>"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"]]);
    $data=@file_get_contents($url,false,$ctx);
    if($data!==false)$http=200;
  }
  if(!$data||$http!=200||strlen($data)>3145728||strlen($data)<50)return null;
  return $data;
}
function extractPageTitle($html,$url){
  if(preg_match('#<title[^>]*>(.*?)</title>#is',$html,$m)){
    $t=trim(html_entity_decode(strip_tags($m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if($t!=='')return mb_substr($t,0,120);
  }
  if(preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']#i',$html,$m))return mb_substr(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),0,120);
  $h=parse_url($url,PHP_URL_HOST);
  return $h?$h:'未命名文章';
}
function extractMainHtml($html){
  $html=preg_replace('#<!--.*?-->#s','',$html);
  $html=preg_replace('#<(script|style|noscript|iframe|nav|header|footer|aside|form)[^>]*>.*?</\1>#is','',$html);
  if(preg_match('#<article[^>]*>(.*?)</article>#is',$html,$m))return $m[1];
  if(preg_match_all('#<(div|main|section)[^>]*(?:class|id)=["\'][^"\']*(?:article|content|post|entry|main)[^"\']*["\'][^>]*>(.*?)</\1>#is',$html,$mm,PREG_SET_ORDER)){
    $best=null;$bestLen=0;
    foreach($mm as $cand){
      $len=strlen(strip_tags($cand[2]));
      if($len>$bestLen){$bestLen=$len;$best=$cand[2];}
    }
    if($best&&$bestLen>80)return $best;
  }
  if(preg_match('#<body[^>]*>(.*?)</body>#is',$html,$m))return $m[1];
  return $html;
}
function htmlToMarkdown($html){
  $html=preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is','',$html);
  $html=htmlTableToMarkdown($html);
  $html=preg_replace('#</(p|div|h[1-6]|li|tr|blockquote|pre|table|ul|ol)>#i',"\n",$html);
  $html=preg_replace('#<h1[^>]*>#i',"\n# ",$html);
  $html=preg_replace('#<h2[^>]*>#i',"\n## ",$html);
  $html=preg_replace('#<h3[^>]*>#i',"\n### ",$html);
  $html=preg_replace('#<h4[^>]*>#i',"\n#### ",$html);
  $html=preg_replace('#<h5[^>]*>#i',"\n##### ",$html);
  $html=preg_replace('#<h6[^>]*>#i',"\n###### ",$html);
  $html=preg_replace('#<li[^>]*>#i',"\n- ",$html);
  $html=preg_replace('#<blockquote[^>]*>#i',"\n> ",$html);
  $html=preg_replace('#<(ul|ol)[^>]*>#i',"\n",$html);
  $html=preg_replace('#<pre[^>]*><code[^>]*>#i',"\n```\n",$html);
  $html=preg_replace('#</code></pre>#i',"\n```\n",$html);
  $html=preg_replace('#<code[^>]*>#i','`',$html);
  $html=preg_replace('#</code>#i','`',$html);
  $html=preg_replace('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is','[$2]($1)',$html);
  $html=preg_replace('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#i','![图片]($1)',$html);
  $html=preg_replace('#<(strong|b)[^>]*>#i','**',$html);
  $html=preg_replace('#</(strong|b)>#i','**',$html);
  $html=preg_replace('#<(em|i)[^>]*>#i','*',$html);
  $html=preg_replace('#</(em|i)>#i','*',$html);
  $html=preg_replace('#<br\s*/?>#i',"\n",$html);
  $html=preg_replace('#<(td|th)[^>]*>#i',' | ',$html);
  $html=preg_replace('#</(td|th)>#i','',$html);
  $html=preg_replace('#</tr>#i',"\n",$html);
  $html=preg_replace('#<table[^>]*>#i',"\n",$html);
  $html=strip_tags($html);
  $html=html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8');
  $html=preg_replace('/[ \t]+/',' ',$html);
  $html=preg_replace('/[ \t]*\n[ \t]*/u',"\n",$html);
  $html=preg_replace('/\n{3,}/',"\n\n",$html);
  return trim($html);
}
function htmlTableToMarkdown($html){
  return preg_replace_callback('#<table[^>]*>(.*?)</table>#is',function($m){
    $rows=[];
    if(preg_match_all('#<tr[^>]*>(.*?)</tr>#is',$m[1],$rm)){
      foreach($rm[1] as $r){
        $cells=[];
        if(preg_match_all('#<t[hd][^>]*>(.*?)</t[hd]>#is',$r,$cm)){
          foreach($cm[1] as $c){
            $c=preg_replace('/<br\s*\/?>/i',"\n",$c);
            $c=preg_replace('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is','[$2]($1)',$c);
            $c=trim(preg_replace('/\s+/u',' ',strip_tags(html_entity_decode($c,ENT_QUOTES|ENT_HTML5,'UTF-8'))));
            $c=str_replace('|','\|',$c);
            $cells[]=$c;
          }
        }
        if($cells)$rows[]=$cells;
      }
    }
    if(!$rows)return '';
    $cols=max(array_map('count',$rows));
    $lines=['| '.implode(' | ',array_pad($rows[0],$cols,'')).' |','| '.implode(' | ',array_fill(0,$cols,'---')).' |'];
    foreach(array_slice($rows,1) as $r)$lines[]='| '.implode(' | ',array_pad($r,$cols,'')).' |';
    return "\n\n".implode("\n",$lines)."\n\n";
  },$html);
}
function absolutizeMarkdownImages($md,$pageUrl){
  $p=parse_url($pageUrl);$scheme=$p['scheme']??'http';$host=$p['host']??'';$baseDir=dirname($pageUrl);
  return preg_replace_callback('/!\[[^\]]*\]\(([^)]+)\)/',function($m)use($scheme,$host,$baseDir){
    $u=trim($m[1]);
    if(preg_match('#^(https?:|data:|#)#i',$u))return $m[0];
    if(strpos($u,'//')===0)return str_replace($u,$scheme.':'.$u,$m[0]);
    if(strpos($u,'/')===0)return str_replace($u,$scheme.'://'.$host.$u,$m[0]);
    return str_replace($u,rtrim($baseDir,'/').'/'.ltrim($u,'./'),$m[0]);
  },$md);
}
function makeUniqueSlug($db,$title){
  $slug=preg_replace('/[^a-z0-9]+/','-',mb_strtolower(trim($title),'UTF-8'));$slug=trim($slug,'-');
  if($slug==='')$slug='post';
  if(mb_strlen($slug)>80)$slug=mb_substr($slug,0,80);
  $q=$db->prepare("SELECT COUNT(*) FROM posts WHERE slug=?");$q->execute([$slug]);$base=$slug;$i=2;
  while((int)$q->fetchColumn()>0){$slug=$base.'-'.$i;$i++;$q->execute([$slug]);}
  return $slug;
}

try{$sn=setting($db,'site_name',SITE_NAME);}catch(Exception $e){$sn=SITE_NAME;}
try{$sd=setting($db,'site_desc');}catch(Exception $e){$sd='';}
try{$catsStmt=$db->prepare("SELECT c.*,(SELECT COUNT(*)FROM post_categories pc JOIN posts p ON p.id=pc.post_id WHERE pc.category_id=c.id AND p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?))as post_count FROM categories c ORDER BY c.sort_order,c.name");$catsStmt->execute([$now]);$cats=$catsStmt->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){$cats=[];}
function buildCatTree($cats,$pid=0){
  $tree=[];
  foreach($cats as $c){if(($c['parent_id']??0)==$pid){$c['children']=buildCatTree($cats,$c['id']);$tree[]=$c;}}
  return $tree;
}
$catTree=buildCatTree($cats);

if($needSetup && !$action){
  echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>安装 MiniBlog</title><link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <style>
  body{font-family:system-ui,-apple-system,sans-serif;background:#f4f6f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
  .box{background:#fff;border-radius:14px;padding:36px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:420px;width:100%;box-sizing:border-box}
  h1{font-size:1.4rem;margin-bottom:6px;text-align:center}p{color:#5a6a7a;font-size:.9rem;text-align:center;margin-bottom:24px}
  input{width:100%;padding:12px 16px;border:1px solid #e0e6ed;border-radius:40px;font-size:.95rem;outline:none;margin-bottom:12px;box-sizing:border-box}
  input:focus{border-color:#2563eb}button{width:100%;padding:12px;background:#2563eb;color:#fff;border:none;border-radius:40px;font-size:1rem;font-weight:600;cursor:pointer}
  .err{color:#ef4444;font-size:.85rem;margin-top:8px;display:none;text-align:center}
  </style>
  <link rel="stylesheet" href="editormd/css/editormd.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
</head><body><div class="box"><h1>🚀 安装 MiniBlog</h1><p>首次使用，请设置管理员账号</p>
  <input type="text" id="su" placeholder="管理员用户名" autofocus><input type="password" id="sp" placeholder="管理员密码">
  <input type="password" id="sp2" placeholder="确认密码" onkeydown="if(event.key===\'Enter\')setup()">
  <button onclick="setup()">开始使用</button><div class="err" id="err"></div></div>
  <script>function setup(){var u=document.getElementById("su").value.trim(),p=document.getElementById("sp").value,p2=document.getElementById("sp2").value;if(!u||!p)return document.getElementById("err").textContent="请填写完整",document.getElementById("err").style.display="block";if(p!==p2)return document.getElementById("err").textContent="两次密码不一致",document.getElementById("err").style.display="block";fetch("?action=setup",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({user:u,pass:p})}).then(function(r){return r.json()}).then(function(d){if(d.ok)location.reload();else document.getElementById("err").textContent=d.error||"安装失败",document.getElementById("err").style.display="block"})}</script></body></html>';
  exit;
}

$isAdminPage=isset($_GET['admin']);
$singlePost=null;
if($slug&&!$isAdminPage){
  $stmt=$db->prepare("SELECT p.*,c.name as cat_name FROM posts p LEFT JOIN categories c ON p.category_id=c.id WHERE p.slug=? AND p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?)");
  $stmt->execute([$slug,$now]);$singlePost=$stmt->fetch(PDO::FETCH_ASSOC);
  if($singlePost){
    if(!empty($singlePost['password'])&&empty($_SESSION['post_pw'][(int)$singlePost['id']])){
      $singlePost['locked']=1;unset($singlePost['content'],$singlePost['excerpt'],$singlePost['password']);
    }else{
      unset($singlePost['password']);
    $wc=postWords($singlePost['content']);$singlePost['words']=$wc['words'];$singlePost['minutes']=$wc['minutes'];
    $db->prepare("UPDATE posts SET views=views+1 WHERE id=?")->execute([$singlePost['id']]);
    trackVisit($db);
    $cs=$db->prepare("SELECT * FROM comments WHERE post_id=? AND approved=1 ORDER BY created_at ASC");$cs->execute([$singlePost['id']]);
    $singlePost['comments']=$cs->fetchAll(PDO::FETCH_ASSOC);$singlePost['content_html']=mdWithAlt($singlePost['content'],$singlePost['title']);
    $tagsMap=loadPostTags($db,[$singlePost['id']]);$singlePost['tags']=$tagsMap[$singlePost['id']]??[];
    $singlePost['cats']=array_map(function($x){return $x['name'];},loadPostCats($db,$singlePost['id']));
    $rel=$db->prepare("SELECT DISTINCT p.id,p.slug,p.title,p.excerpt,p.created_at,p.views FROM posts p JOIN post_tags pt ON pt.post_id=p.id WHERE p.id<>? AND p.published=1 AND p.deleted_at IS NULL AND (p.password IS NULL OR p.password='') AND (p.publish_at IS NULL OR p.publish_at<=?) AND pt.tag_id IN (SELECT tag_id FROM post_tags WHERE post_id=?) ORDER BY p.views DESC LIMIT 5");
    $rel->execute([$singlePost['id'],$now,$singlePost['id']]);$singlePost['related']=$rel->fetchAll(PDO::FETCH_ASSOC);
    if(!$singlePost['related']){
      $catQ=$db->prepare("SELECT category_id FROM post_categories WHERE post_id=?");$catQ->execute([$singlePost['id']]);$relCats=$catQ->fetchAll(PDO::FETCH_COLUMN);
      if($singlePost['category_id']&&!in_array($singlePost['category_id'],$relCats,true))$relCats[]=$singlePost['category_id'];
      if($relCats){
        $ph=implode(',',array_fill(0,count($relCats),'?'));
        $rel=$db->prepare("SELECT DISTINCT p.id,p.slug,p.title,p.excerpt,p.created_at,p.views FROM posts p WHERE p.id<>? AND (p.category_id IN ($ph) OR p.id IN (SELECT pc.post_id FROM post_categories pc WHERE pc.category_id IN ($ph))) AND p.published=1 AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at<=?) ORDER BY p.views DESC LIMIT 5");
        $rel->execute(array_merge([$singlePost['id']],$relCats,$relCats,[$now]));$singlePost['related']=$rel->fetchAll(PDO::FETCH_ASSOC);
      }
    }
    $pv=$db->prepare("SELECT slug,title FROM posts WHERE id<>? AND published=1 AND deleted_at IS NULL AND (password IS NULL OR password='') AND (publish_at IS NULL OR publish_at<=?) AND (created_at<? OR (created_at=? AND id<?)) ORDER BY created_at DESC,id DESC LIMIT 1");
    $pv->execute([$singlePost['id'],$now,$singlePost['created_at'],$singlePost['created_at'],$singlePost['id']]);$singlePost['prev']=$pv->fetch(PDO::FETCH_ASSOC);
    $nx=$db->prepare("SELECT slug,title FROM posts WHERE id<>? AND published=1 AND deleted_at IS NULL AND (password IS NULL OR password='') AND (publish_at IS NULL OR publish_at<=?) AND (created_at>? OR (created_at=? AND id>?)) ORDER BY created_at ASC,id ASC LIMIT 1");
    $nx->execute([$singlePost['id'],$now,$singlePost['created_at'],$singlePost['created_at'],$singlePost['id']]);$singlePost['next']=$nx->fetch(PDO::FETCH_ASSOC);
    }
  }
}
if(!$isAdminPage&&$action===''&&!$slug&&!$needSetup)trackVisit($db);

function includeArchive($cats){
  global $db,$now;
  $months=$db->prepare("SELECT strftime('%Y-%m',created_at) m,COUNT(*) c FROM posts WHERE published=1 AND deleted_at IS NULL AND (password IS NULL OR password='') AND (publish_at IS NULL OR publish_at<=?) GROUP BY m ORDER BY m DESC");
  $months->execute([$now]);$months=$months->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <div class="container">
<h2 style="margin:22px 0 12px;font-size:1.3rem"><?=ico('calendar',18)?> 文章归档</h2>
    <?php if(!$months):?><div class="empty"><p>暂无文章</p></div><?php endif;?>
    <?php foreach($months as $mo):?>
      <div style="margin-bottom:22px">
        <h3 style="font-size:1rem;margin-bottom:8px"><?=htmlspecialchars($mo['m'])?>（<?=(int)$mo['c']?> 篇）</h3>
        <?php $stmt=$db->prepare("SELECT slug,title,created_at FROM posts WHERE published=1 AND deleted_at IS NULL AND (password IS NULL OR password='') AND (publish_at IS NULL OR publish_at<=?) AND strftime('%Y-%m',created_at)=? ORDER BY created_at DESC");
        $stmt->execute([$now,$mo['m']]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $p):?>
<div style="display:flex;gap:10px;align-items:baseline;padding:7px 0;border-bottom:1px dashed var(--border);font-size:.9rem">
            <span style="color:var(--t3);font-size:.78rem;flex:none"><?=substr($p['created_at'],0,10)?></span>
            <a href="?slug=<?=urlencode($p['slug'])?>" style="color:var(--t1);text-decoration:none"><?=htmlspecialchars($p['title'])?></a>
          </div>
        <?php endforeach;?>
      </div>
    <?php endforeach;?>
  </div>
  <?php
}

function includeHome($cats){
  global $catTree,$db,$now;
  $curCatId=intval($_GET['cat']??0);
  $catMap=[];foreach($cats as $c)$catMap[$c['id']]=$c;
  $breadcrumb=[];$tid=$curCatId;
  while($tid>0&&isset($catMap[$tid])){$breadcrumb[]=$catMap[$tid];$tid=$catMap[$tid]['parent_id']??0;}
  $breadcrumb=array_reverse($breadcrumb);
  ?>
<div class="container home-layout">
  <div class="home-main">
  <div class="search-bar">
    <input type="text" id="searchInput" placeholder="搜索文章..." onkeydown="if(event.key==='Enter')searchPosts()">
    <button onclick="searchPosts()"><?=ico('search',15)?> 搜索</button>
  </div>
  <div id="hotSearches" class="hot-searches"></div>
  <?php if(!empty($breadcrumb)):?>
  <div class="breadcrumb">
    <a href="?"><?=ico('home',15)?> 首页</a>
    <?php foreach($breadcrumb as $b):?>
    <span class="sep">›</span>
    <a href="?cat=<?=$b['id']?>" class="<?=$b['id']==$curCatId?'current':''?>"><?=htmlspecialchars($b['name'])?></a>
    <?php endforeach;?>
  </div>
  <?php endif;?>
  <?php if(!empty($catTree)):?>
  <div class="cat-nav">
    <a href="?" class="cat-pill <?=!$curCatId?'active':''?>">全部</a>
    <?php
    function renderCatLevel($tree,$curCatId,$depth=0){
      foreach($tree as $c){
        $hasChildren=!empty($c['children']);
        $cls='cat-pill'.($c['id']==$curCatId?' active':'').($hasChildren?' has-children':'');
        echo '<div class="cat-group">';
        echo '<a href="?cat='.$c['id'].'" class="'.$cls.'">'.htmlspecialchars($c['name']).' <span class="cat-count">'.$c['post_count'].'</span>';
        if($hasChildren)echo ' <span class="arrow">▾</span>';
        echo '</a>';
        if($hasChildren){
          echo '<div class="cat-dropdown">';
          renderCatLevel($c['children'],$curCatId,$depth+1);
          echo '</div>';
        }
        echo '</div>';
      }
    }
    renderCatLevel($catTree,$curCatId);
    ?>
  </div>
  <?php endif;?>
  <?php
  $curTag=trim($_GET['tag']??'');
  $allTags=$db->query("SELECT slug,name,COUNT(pt.post_id) c FROM tags t LEFT JOIN post_tags pt ON pt.tag_id=t.id GROUP BY t.id ORDER BY c DESC,t.name LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
  ?>
<?php if($curTag):?><div class="tag-title"><?=ico('tag',15)?> 标签：<?=htmlspecialchars($curTag)?></div><?php endif;?>
  <?php if($allTags):?>
  <div class="tags-bar">
    <?php foreach($allTags as $tg):?><a href="?tag=<?=urlencode($tg['slug'])?>" class="tag-pill <?=$curTag===$tg['slug']?'active':''?>"><?=htmlspecialchars($tg['name'])?> <span class="tag-count"><?=(int)$tg['c']?></span></a><?php endforeach;?>
  </div>
  <?php endif;?>
  <div class="posts" id="postsList"><div class="loading"><span class="spinner"></span>加载中...</div></div>
  <div class="pagination" id="pagination"></div>
  </div>
  <aside class="home-aside">
    <?php
  $snAside=setting($db,'site_name',SITE_NAME);
  $authorName=trim(setting($db,'author_name'))?:$snAside;
  $authorNameIsFallback=(trim(setting($db,'author_name'))==='');
    $authorBio=trim(setting($db,'author_bio'));
    $authorAvatar=trim(setting($db,'author_avatar'));
    $pCount=(int)$db->query("SELECT COUNT(*) FROM posts WHERE published=1 AND deleted_at IS NULL AND (password IS NULL OR password='')")->fetchColumn();
    $tCount=(int)$db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
    $cCount=(int)$db->query("SELECT COUNT(*) FROM comments WHERE approved=1")->fetchColumn();
    ?>
    <div class="aside-card author-card">
      <div class="avatar"><?php if($authorAvatar!==''):?><img src="<?=htmlspecialchars($authorAvatar)?>" alt="avatar"><?php else:?><?=htmlspecialchars(mb_substr($authorName,0,1))?><?php endif;?></div>
<div class="name"<?=($authorNameIsFallback?' style="font-family:'.htmlspecialchars(siteFontCss($db),ENT_QUOTES).'"':'')?>><?=htmlspecialchars($authorName)?></div>
      <?php if($authorBio!==''):?><div class="bio"><?=htmlspecialchars($authorBio)?></div><?php endif;?>
      <div class="stats">
        <span><b><?=$pCount?></b>文章</span>
        <span><b><?=$tCount?></b>标签</span>
        <span><b><?=$cCount?></b>评论</span>
      </div>
    </div>
    <?php if(!empty($catTree)):?>
    <div class="aside-card">
<div class="aside-title"><?=ico('folder',15)?> 栏目</div>
      <div class="aside-list">
        <?php
        $rc=function($tree)use(&$rc){
          foreach($tree as $c){
            echo '<a href="?cat='.(int)$c['id'].'" class="aside-cat"><span>'.htmlspecialchars($c['name']).'</span><em>'.(int)$c['post_count'].'</em></a>';
            if(!empty($c['children']))$rc($c['children']);
          }
        };
        $rc($catTree);
        ?>
      </div>
    </div>
<?php endif;?>
<?php
$latest=$db->prepare("SELECT slug,title,created_at FROM posts WHERE published=1 AND deleted_at IS NULL AND (password IS NULL OR password='') AND (publish_at IS NULL OR publish_at<=?) ORDER BY created_at DESC LIMIT 10");
$latest->execute([$now]);$latest=$latest->fetchAll(PDO::FETCH_ASSOC);
$latest5=array_slice($latest,0,5);
$latestMore=array_slice($latest,5);
?>
<?php if($latest5):?>
<div class="aside-card">
<div class="aside-title"><?=ico('clock',15)?> 最新文章</div>
<div class="aside-list">
<?php foreach($latest5 as $lp):?>
<a href="?slug=<?=urlencode($lp['slug'])?>" class="aside-cat"><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($lp['title'])?></span><em style="flex:none;min-width:42px;text-align:center"><?=substr($lp['created_at'],5,5)?></em></a>
<?php endforeach;?>
<?php if($latestMore):?>
<div id="latestMoreBox" style="display:none">
<?php foreach($latestMore as $lp):?>
<a href="?slug=<?=urlencode($lp['slug'])?>" class="aside-cat"><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($lp['title'])?></span><em style="flex:none;min-width:42px;text-align:center"><?=substr($lp['created_at'],5,5)?></em></a>
<?php endforeach;?>
</div>
<a href="javascript:void(0)" onclick="toggleLatest(this)" class="latest-toggle">展开更多 ▾</a>
<?php endif;?>
</div>
</div>
<?php endif;?>
<?php if(isAdmin()):?>
<div class="aside-card">
<div class="aside-title"><?=ico('home',15)?> 常用导航</div>
<div class="aside-list">
<a href="?admin=posts" class="aside-cat"><?=ico('file-text',14,'#2563eb')?> <span>文章管理</span></a>
<a href="?admin=trash" class="aside-cat"><?=ico('trash',14,'#ef4444')?> <span>回收站</span></a>
<a href="?admin=stats" class="aside-cat"><?=ico('chart',14,'#16a34a')?> <span>统计</span></a>
<a href="?admin=files" class="aside-cat"><?=ico('folder',14,'#f59e0b')?> <span>文件管理</span></a>
<a href="?admin=settings" class="aside-cat"><?=gearIcon(14,'#8b5cf6')?> <span>系统设置</span></a>
</div>
</div>
<?php endif;?>
<?php if(!empty($allTags)):?>
    <div class="aside-card">
<div class="aside-title"><?=ico('tag',15)?> 标签</div>
      <div class="aside-tags">
        <?php foreach($allTags as $tg):?><a href="?tag=<?=urlencode($tg['slug'])?>" class="<?=$curTag===$tg['slug']?'active':''?>"><?=htmlspecialchars($tg['name'])?></a><?php endforeach;?>
      </div>
    </div>
    <?php endif;?>
  </aside>
</div>
<script>
function loadPosts(page){
  page=page||1;
  var cat=new URLSearchParams(location.search).get('cat')||'';
  var tag=new URLSearchParams(location.search).get('tag')||'';
  var search=document.getElementById('searchInput')?document.getElementById('searchInput').value:'';
  var url='?action=posts&page='+page;
  if(cat)url+='&cat='+cat;
  if(tag)url+='&tag='+encodeURIComponent(tag);
  if(search)url+='&search='+encodeURIComponent(search);
  fetch(url).then(function(r){return r.json()}).then(function(d){
    var el=document.getElementById('postsList');
    var curSearch=search||d.search||'';
    function hl(text){
      if(!text)return '';
      var safe=escapeHtml(text);
      if(!curSearch)return safe;
      try{var re=new RegExp('('+curSearch.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');return safe.replace(re,'<mark>$1</mark>');}catch(e){return safe;}
    }
    if(d.error){el.innerHTML='<div class="empty"><div class="empty-icon"><?=ico('alert',30)?></div><p>'+escapeHtml(d.error)+'</p></div>';document.getElementById('pagination').innerHTML='';return;}
    if(!d.posts||!d.posts.length){
el.innerHTML='<div class="empty"><div class="empty-icon"><?=ico('search',30)?></div><p>'+(curSearch?'没有找到与“'+escapeHtml(curSearch)+'”相关的结果':'暂无文章')+'</p></div>';
      document.getElementById('pagination').innerHTML='';return;
    }
    // ★ 显示缩略图：有图显示图片，无图显示渐变 SVG
    el.innerHTML=d.posts.map(function(p){
      var thumbHtml;
      if(p.thumbnail){
        thumbHtml = p.thumbnail.indexOf('<svg')===0 ? p.thumbnail : '<img src="'+p.thumbnail+'" alt="'+escapeHtml(p.title)+'" loading="lazy">';
      } else {
        // 极少数情况没有缩略图，显示首字母
        var thumbChar = escapeHtml((p.title || 'B').charAt(0).toUpperCase());
        thumbHtml = '<span class="thumb-letter">'+thumbChar+'</span>';
      }
      var tagsHtml='';
      (p.tags||[]).forEach(function(t){tagsHtml+='<a class="tag-chip" href="?tag='+encodeURIComponent(t.slug)+'">'+escapeHtml(t.name)+'</a>'});
var excerptHtml=p.locked?'<div class="lock-tip"><?=ico('lock',13)?> 私密文章</div>':'<div class="excerpt">'+hl(p.content)+'</div>';
      var dateStr=escapeHtml((p.created_at||'').split(' ')[0]);
      return '<article class="post-card">'+
        '<a href="?slug='+encodeURIComponent(p.slug)+'" class="post-thumb">'+thumbHtml+'<time>'+dateStr+'</time></a>'+
        '<div class="post-body">'+
        (p.cat_name?'<span class="cat">'+escapeHtml(p.cat_name)+'</span>':'')+
        '<h2>'+(p.pinned?'<span style="background:#f59e0b;color:#fff;font-size:.68rem;padding:2px 8px;border-radius:20px;margin-right:6px;vertical-align:2px">置顶</span>':'')+'<a href="?slug='+encodeURIComponent(p.slug)+'">'+hl(p.title)+(p.locked?' <?=ico('lock',12)?>':'')+'</a></h2>'+
        excerptHtml+
        '<div class="meta">'+
'<span><?=ico('calendar',12)?> '+dateStr+'</span>'+
'<span><?=ico('eye',12)?> '+p.views+'</span>'+
(p.comment_count!=null?'<span><?=ico('message',12)?> '+p.comment_count+'</span>':'')+
        '</div>'+
        (tagsHtml?'<div class="card-tags">'+tagsHtml+'</div>':'')+
        '<a href="?slug='+encodeURIComponent(p.slug)+'" class="read-more">阅读全文 →</a>'+
        '</div></article>';
    }).join('');
    var pg=document.getElementById('pagination');
    if(d.pages<=1){pg.innerHTML='';return}
    var html='<button '+(page<=1?'disabled':'')+' onclick="loadPosts('+(page-1)+')">‹</button>';
    for(var i=Math.max(1,page-2);i<=Math.min(d.pages,page+2);i++)
      html+='<button class="'+(i==page?'active':'')+'" onclick="loadPosts('+i+')">'+i+'</button>';
    html+='<button '+(page>=d.pages?'disabled':'')+' onclick="loadPosts('+(page+1)+')">›</button>';
    pg.innerHTML=html;
    if(window._postsLoaded)window.scrollTo({top:0,behavior:'smooth'});
    window._postsLoaded=true;
  }).catch(function(){
    var el=document.getElementById('postsList');
    if(el)el.innerHTML='<div class="empty">加载失败，请刷新重试</div>';
  });
}
function searchPosts(){loadPosts(1)}
function loadHotSearches(){
  var box=document.getElementById('hotSearches');if(!box)return;
  fetch('?action=hot_searches').then(function(r){return r.json()}).then(function(d){
    if(!d||!d.length){box.innerHTML='';return;}
    box.innerHTML='<span class="hot-label">热门：</span>'+d.map(function(s){return '<a class="hot-link" href="javascript:void(0)" onclick="var i=document.getElementById(\'searchInput\');i.value=decodeURIComponent(\''+encodeURIComponent(s.keyword)+'\');searchPosts()">'+escapeHtml(s.keyword)+'</a>'}).join('');
  }).catch(function(){});
}
document.addEventListener('DOMContentLoaded',function(){
  if('ontouchstart' in window||window.innerWidth<=768){
    document.querySelectorAll('.cat-group>.cat-pill.has-children').forEach(function(pill){
      pill.addEventListener('click',function(e){
        var group=this.parentElement;var drop=group.querySelector('.cat-dropdown');
        if(drop){
          var isOpen=drop.style.display==='block';
          document.querySelectorAll('.cat-dropdown').forEach(function(d){d.style.display='';});
          if(!isOpen){e.preventDefault();drop.style.display='block';}
        }
      });
    });
    document.addEventListener('click',function(e){
      if(!e.target.closest('.cat-group'))document.querySelectorAll('.cat-dropdown').forEach(function(d){d.style.display='';});
    });
  }
});
function toggleLatest(el){
  var box=document.getElementById('latestMoreBox');
  if(!box)return;
  var open=box.style.display!=='none';
  box.style.display=open?'none':'block';
  if(el)el.textContent=open?'展开更多 ▾':'收起 ▴';
}
loadPosts(1);
loadHotSearches();
</script>
<?php }

function includePost($post,$cats,$db){?>
<div class="container">
  <div class="breadcrumb">
    <a href="?"><?=ico('home',15)?> 首页</a>
    <span class="sep">›</span>
    <span><?=htmlspecialchars((string)($post['cat_name']?:'正文'))?></span>
  </div>
  <article class="single-post" data-id="<?= (int)$post['id'] ?>">
    <?php $allCats=array_values(array_unique(array_merge(array_filter([$post['cat_name']??'']),$post['cats']??[])));?>
    <?php if($allCats):?><div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:10px"><?php foreach($allCats as $cn):?><span class="cat"><?=htmlspecialchars($cn)?></span><?php endforeach;?></div><?php endif;?>
    <ul class="nav-links nav-links-top joe_post__pagination">
      <li class="joe_post__pagination-item prev"><?php if(!empty($post['prev'])):?><a href="?slug=<?=urlencode($post['prev']['slug'])?>" title="<?=htmlspecialchars($post['prev']['title'])?>">上一篇</a><?php endif;?></li>
      <li class="joe_post__pagination-item next"><?php if(!empty($post['next'])):?><a href="?slug=<?=urlencode($post['next']['slug'])?>" title="<?=htmlspecialchars($post['next']['title'])?>">下一篇</a><?php endif;?></li>
    </ul>
    <h1><?=htmlspecialchars($post['title'])?></h1>
    <?php if(isAdmin()): ?>
      <div style="margin-bottom:12px;">
<button onclick="openEditor(<?= (int)$post['id'] ?>)" class="btn btn-primary" style="padding:6px 16px; font-size:.8rem;"><?=ico('edit',13)?> 编辑此文章</button>
      </div>
    <?php endif; ?>
<div class="meta"><span><?=ico('calendar',13)?> <?=substr($post['created_at'],0,10)?></span><span><?=ico('eye',13)?> <?=$post['views']?> 次阅读</span><?php if(!empty($post['words'])):?><span><?=ico('file-text',13)?> <?=(int)$post['words']?> 字 · <?=ico('clock',13)?> <?=(int)$post['minutes']?> 分钟</span><?php endif;?></div>
    <?php if(!empty($post['tags'])):?>
    <div class="post-tags"><?php foreach($post['tags'] as $tg):?><a class="tag-chip" href="?tag=<?=urlencode($tg['slug'])?>">#<?=htmlspecialchars($tg['name'])?></a><?php endforeach;?></div>
    <?php endif;?>
    <div id="tocBox" class="toc-box"></div>
    <div class="content"><?=$post['content_html']??mdWithAlt($post['content'],$post['title'])?></div>
    <?php if(isAdmin()):?>
    <div style="text-align:center;margin-top:22px"><button onclick="openEditor(<?= (int)$post['id'] ?>)" class="btn btn-primary" style="padding:8px 24px;font-size:.85rem;"><?=ico('edit',13)?> 编辑此文章</button></div>
    <?php endif;?>

    <!-- 评论区域 -->
    <div class="comments">
        <h3>评论（<?= count($post['comments']) ?>）</h3>
        <div id="commentList">
        <?php
        $cmap=[];foreach($post['comments'] as $c)$cmap[$c['id']]=$c;
        $roots=[];foreach($cmap as $cid=>$c){if(empty($c['parent_id'])||!isset($cmap[$c['parent_id']]))$roots[]=$cid;}
        $renderTree=function($ids)use(&$cmap,&$renderTree){
          foreach($ids as $cid){$c=$cmap[$cid];
            echo '<div class="comment'.(!empty($c['parent_id'])?' reply':'').'" id="comment-'.(int)$cid.'">';
            echo '<strong>'.htmlspecialchars($c['author']).'</strong><time>'.htmlspecialchars($c['created_at']).'</time>';
            echo '<p>'.nl2br(htmlspecialchars($c['content'])).'</p>';
            echo '<button type="button" class="reply-btn" onclick="setReply('.(int)$cid.','.htmlspecialchars(json_encode($c['author'],JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES).')">回复</button>';
            $kids=[];foreach($cmap as $k=>$v)if(intval($v['parent_id'])===$cid)$kids[]=$k;
            if($kids){echo '<div class="comment-children">';$renderTree($kids);echo '</div>';}
            echo '</div>';
          }
        };
        if($roots)$renderTree($roots);
        ?>
        </div>

        <?php $ca=random_int(1,9);$cb=random_int(1,9);$_SESSION['captcha_answer']=$ca+$cb;$_SESSION['captcha_ts']=time();?>
        <form id="commentForm" onsubmit="return submitComment(event, <?= (int)$post['id'] ?>)">
            <input type="hidden" name="parent_id" id="replyTo" value="0">
            <div id="replyHint" style="display:none;font-size:.78rem;color:var(--t3);margin-bottom:6px"></div>
            <input type="text" name="author" placeholder="昵称" required>
            <input type="email" name="email" placeholder="邮箱（选填）">
            <textarea name="content" placeholder="说点什么..." required></textarea>
            <div class="form-row" style="align-items:center">
              <span style="font-size:.85rem;color:var(--t2)"><?=$ca?> + <?=$cb?> =</span>
              <input type="text" name="captcha" placeholder="验证码" required style="max-width:120px">
            </div>
            <button type="submit">提交评论</button>
        </form>
    </div>

    <?php if(!empty($post['related'])):?>
    <div class="related-posts">
      <h3>相关文章</h3>
      <ul>
      <?php foreach($post['related'] as $r):?>
        <li><a href="?slug=<?=urlencode($r['slug'])?>"><?=htmlspecialchars($r['title'])?></a></li>
      <?php endforeach;?>
      </ul>
    </div>
    <?php endif;?>

    <ul class="nav-links joe_post__pagination">
      <li class="joe_post__pagination-item prev"><?php if(!empty($post['prev'])):?><a href="?slug=<?=urlencode($post['prev']['slug'])?>" title="<?=htmlspecialchars($post['prev']['title'])?>">上一篇</a><?php endif;?></li>
      <li class="joe_post__pagination-item next"><?php if(!empty($post['next'])):?><a href="?slug=<?=urlencode($post['next']['slug'])?>" title="<?=htmlspecialchars($post['next']['title'])?>">下一篇</a><?php endif;?></li>
    </ul>
    <div style="text-align:center;margin-top:16px"><a href="?" class="back-home">← 返回首页</a></div>
  </article>
</div>
<script>
function setReply(id,name){
  var box=document.getElementById('replyTo');var hint=document.getElementById('replyHint');
  if(box)box.value=id||0;
  if(hint){hint.style.display=id?'block':'none';hint.textContent=id?('正在回复 '+name+'，点这里取消'):'';hint.onclick=function(){box.value=0;hint.style.display='none';};}
  var f=document.getElementById('commentForm');if(f)f.scrollIntoView({behavior:'smooth',block:'center'});
}
document.querySelectorAll('.content pre').forEach(function(pre){
  var btn=document.createElement('button');
  btn.className='copy-btn';btn.textContent='复制';
  btn.onclick=function(){
    var code=pre.querySelector('code')||pre;
    var text=code.innerText||code.textContent;
    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(function(){showOk(btn)}).catch(function(){fallbackCopy(text,btn)});
    }else fallbackCopy(text,btn);
  };
  pre.appendChild(btn);
});
function showOk(btn){btn.textContent='✓ 已复制';btn.classList.add('copied');setTimeout(function(){btn.textContent='复制';btn.classList.remove('copied')},2000)}
function fallbackCopy(text,btn){var ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();try{document.execCommand('copy');showOk(btn)}catch(e){btn.textContent='失败'}document.body.removeChild(ta)}
</script>
<?php }

$page=$_GET['admin']??'posts';
$siteTheme=setting($db,'site_theme','blue');if(!in_array($siteTheme,['blue','warm','light','dark'],true))$siteTheme='blue';
$adminContent='<div class="loading"><span class="spinner"></span>加载中...</div>';
?><!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="?action=favicon">
<link rel="apple-touch-icon" href="?action=favicon&png=1">
<script>
try{
  var t='<?=$siteTheme?>';
  if(t==='dark'){document.documentElement.classList.add('dark');}
  else if(t==='warm'){document.documentElement.classList.add('warm');}
  else if(t==='blue'){document.documentElement.classList.add('blue');}
  var fv=document.querySelector('link[rel="icon"]');if(fv)fv.href='?action=favicon&theme='+t;
  var av=document.querySelector('link[rel="apple-touch-icon"]');if(av)av.href='?action=favicon&png=1&theme='+t;
}catch(e){}
</script>
<title><?=htmlspecialchars($singlePost?$singlePost['title'].' - ':'')?><?=htmlspecialchars($sn)?></title>
<?php if($isAdminPage):?><meta name="robots" content="noindex,nofollow"><?php endif;?>
<meta name="description" content="<?=htmlspecialchars($singlePost?strip_tags(mb_substr($singlePost['content'],0,150)):$sd)?>">
<?php $canonical=$singlePost?SITE_URL.'/?slug='.urlencode($singlePost['slug']):SITE_URL.'/';?>
<link rel="canonical" href="<?=htmlspecialchars($canonical)?>">
<meta property="og:type" content="<?=$singlePost?'article':'website'?>">
<meta property="og:title" content="<?=htmlspecialchars($singlePost?$singlePost['title']:$sn)?>">
<meta property="og:description" content="<?=htmlspecialchars($singlePost?strip_tags(mb_substr($singlePost['content'],0,150)):$sd)?>">
<meta property="og:url" content="<?=htmlspecialchars($canonical)?>">
<meta property="og:site_name" content="<?=htmlspecialchars($sn)?>">
<meta name="twitter:card" content="summary">
<?php if($singlePost):
$ld=['@context'=>'https://schema.org','@type'=>'BlogPosting','headline'=>$singlePost['title'],'datePublished'=>$singlePost['created_at'],'dateModified'=>$singlePost['updated_at']??$singlePost['created_at'],'author'=>['@type'=>'Person','name'=>$sn],'description'=>strip_tags(mb_substr($singlePost['content'],0,200)),'mainEntityOfPage'=>$canonical];?>
<script type="application/ld+json"><?=json_encode($ld,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<?php endif;?>
<link rel="alternate" type="application/rss+xml" title="<?=htmlspecialchars($sn)?>" href="?action=rss">
<link rel="manifest" href="?action=manifest">
<meta name="theme-color" content="#2563eb">
  <style>
  @font-face{font-family:'MaShanZheng';src:url('fonts/ma-shan-zheng.woff2?v=20260803') format('woff2');font-weight:400;font-style:normal;font-display:swap}
  @font-face{font-family:'GreatVibes';src:url('fonts/great-vibes.woff2?v=20260803') format('woff2');font-weight:400;font-style:normal;font-display:swap}
  @font-face{font-family:'Allura';src:url('fonts/allura.woff2?v=20260803') format('woff2');font-weight:400;font-style:normal;font-display:swap}
  @font-face{font-family:'DancingScript';src:url('fonts/dancing-script.woff2?v=20260803') format('woff2');font-weight:400;font-style:normal;font-display:swap}
  *{margin:0;padding:0;box-sizing:border-box;text-decoration:none;list-style:none}
:root{--bg:#f0f4f9;--card:#fff;--t1:#0f172a;--t2:#475569;--t3:#94a3b8;--b:#2563eb;--bh:#1d4ed8;--g1:#2563eb;--g2:#7c3aed;--br:16px;--s:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);--sm:0 4px 20px rgba(0,0,0,.08);--radius-sm:8px;--radius-md:12px;--radius-lg:16px;--radius-xl:24px}
body{background:var(--bg);font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--t1);min-height:100vh;display:flex;flex-direction:column;background-image:radial-gradient(ellipse at 50% 0%,rgba(37,99,235,.04) 0%,transparent 60%)}
.container{max-width:900px;margin:0 auto;padding:0 20px;width:100%}
header{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(0,0,0,.06);position:sticky;top:0;z-index:100;backdrop-filter:blur(16px) saturate(180%);-webkit-backdrop-filter:blur(16px) saturate(180%)}
.header-inner{max-width:900px;margin:0 auto;padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;height:60px}
.logo{font-size:1.25rem;font-weight:800;background:linear-gradient(135deg,var(--g1),var(--g2));-webkit-background-clip:text;background-clip:text;color:transparent;letter-spacing:-.5px}
header nav{display:flex;gap:4px;align-items:center}
header nav a, .theme-btn{color:var(--t2);font-size:.85rem;padding:6px 14px;border-radius:40px;transition:all .2s;font-weight:500}
header nav a:hover, .theme-btn:hover{color:var(--b);background:#eef2ff}
header nav a.active{color:#fff;background:linear-gradient(135deg,var(--g1),var(--g2));box-shadow:0 2px 8px rgba(37,99,235,.2)}
.theme-btn{background:none;border:none;cursor:pointer;font-size:1rem}
footer{margin-top:auto;text-align:center;padding:32px 20px;color:var(--t3);font-size:.8rem;border-top:1px solid rgba(0,0,0,.05);background:rgba(255,255,255,.6)}
.posts{display:grid;grid-template-columns:1fr;gap:16px;padding:24px 0}
.post-card{background:var(--card);border-radius:var(--radius-md);padding:24px;box-shadow:var(--s);transition:all .3s cubic-bezier(.4,0,.2,1);border:1px solid rgba(0,0,0,.04);position:relative;overflow:hidden}
.post-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--g1),var(--g2));opacity:0;transition:opacity .3s}
.post-card:hover{box-shadow:var(--sm);transform:translateY(-3px);border-color:rgba(37,99,235,.1)}
.post-card:hover::before{opacity:1}
.post-card .cat{display:inline-block;font-size:.7rem;color:var(--b);background:#eef2ff;padding:3px 10px;border-radius:40px;font-weight:600;margin-bottom:10px;letter-spacing:.3px}
.post-card h2{font-size:1.2rem;margin-bottom:8px;line-height:1.45}
.post-card h2 a{color:var(--t1);transition:color .2s}
.post-card h2 a:hover{color:var(--b)}
.post-card .meta{font-size:.78rem;color:var(--t3);margin-bottom:12px;display:flex;gap:16px;align-items:center}
.post-card .meta span{display:flex;align-items:center;gap:4px}
.post-card .excerpt{font-size:.88rem;color:var(--t2);line-height:1.7;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.post-card .read-more{color:var(--b);font-weight:600;font-size:.82rem;display:inline-flex;align-items:center;gap:4px;transition:gap .2s}
.post-card .read-more:hover{gap:8px}
.single-post{background:var(--card);border-radius:var(--radius-lg);padding:36px;box-shadow:var(--s);margin:28px 0;border:1px solid rgba(0,0,0,.04);position:relative;overflow:hidden}
.single-post::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--g1),var(--g2))}
.single-post .cat{display:inline-block;font-size:.72rem;color:var(--b);background:#eef2ff;padding:3px 12px;border-radius:40px;font-weight:600;margin-bottom:14px;letter-spacing:.3px}
.single-post h1{font-size:1.8rem;margin-bottom:14px;line-height:1.35;letter-spacing:-.3px;font-weight:800}
.single-post .meta{font-size:.82rem;color:var(--t3);margin-bottom:24px;display:flex;gap:20px;flex-wrap:wrap;padding-bottom:20px;border-bottom:1px solid rgba(0,0,0,.06)}
.single-post .meta span{display:flex;align-items:center;gap:4px}
.single-post .content{font-size:1.02rem;line-height:1.85;color:var(--t1)}
.single-post .content table{border-collapse:collapse;width:100%;margin:16px 0;font-size:.92rem}
.single-post .content table th,.single-post .content table td{border:1px solid #d7dee8;padding:8px 12px;text-align:left;vertical-align:top}
.single-post .content table th{background:#f1f5f9;font-weight:600}
.dark .single-post .content table th{background:#1c2128}
.dark .single-post .content table th,.dark .single-post .content table td{border-color:var(--border)}
.single-post .content p{margin-bottom:18px}.single-post .content img{max-width:100%;border-radius:var(--radius-sm);margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.single-post .content blockquote{border-left:4px solid var(--b);padding:16px 24px;margin:20px 0;background:linear-gradient(90deg,#f8faff,transparent);border-radius:0 var(--radius-sm) var(--radius-sm) 0;color:var(--t2);font-style:italic}
.single-post .content code{background:rgba(110,118,129,.2);color:#ffa657;padding:2px 8px;border-radius:6px;font-size:.95em;font-weight:500}
.single-post .content ul{margin:16px 0;padding-left:24px}.single-post .content ul li{list-style:disc;margin-bottom:8px;padding-left:4px}
.single-post .content a{color:var(--b);text-decoration:underline;text-underline-offset:2px}
.single-post .content a[href*="/uploads/"]{display:inline-flex;align-items:center;gap:6px;background:#eef2ff;padding:3px 12px;border-radius:40px;font-size:.85rem;font-weight:500;text-decoration:none;margin:2px 0;transition:all .2s}
.single-post .content a[href*="/uploads/"]:hover{background:#dbeafe;transform:translateY(-1px)}
.single-post .nav-links{display:flex;justify-content:space-between;margin-top:36px;padding:0;border:0}
.single-post .nav-links-top{margin:0 0 16px}
.single-post .nav-links-top .joe_post__pagination-item{margin-bottom:0}
.single-post .joe_post__pagination-item{margin-bottom:15px}
.single-post .joe_post__pagination-item a{display:block;height:32px;line-height:32px;padding:0 15px;color:#fff;font-size:12px;background:var(--b);border-radius:3px;box-shadow:0 2px 8px rgba(37,99,235,.2);transition:background .2s,transform .2s}
.single-post .joe_post__pagination-item a:hover{background:var(--bh);animation:pulse 1s;box-shadow:0 0 0 20px rgba(255,255,255,0)}
.single-post .joe_post__pagination-item.next{margin-left:auto}
@keyframes pulse{0%{transform:scaleX(1)}50%{transform:scale3d(1.05,1.05,1.05)}to{transform:scaleX(1)}}
.search-bar{display:flex;gap:8px;margin:20px 0 0}.search-bar input{flex:1;padding:11px 18px;border:1px solid #e2e8f0;border-radius:40px;font-size:.9rem;outline:none;transition:all .2s;background:#fff}
.search-bar input:focus{border-color:var(--b);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.search-bar button{padding:11px 22px;background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;border:none;border-radius:40px;font-weight:600;cursor:pointer;font-size:.88rem;transition:all .2s;box-shadow:0 2px 8px rgba(37,99,235,.2)}
.search-bar button:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,.3)}
.categories-bar{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0 0}
.categories-bar a{font-size:.82rem;padding:5px 15px;border-radius:40px;background:var(--card);color:var(--t2);border:1px solid #e2e8f0;transition:all .2s;font-weight:500}
.categories-bar a:hover,.categories-bar a.active{background:var(--b);color:#fff;border-color:var(--b)}

/* 面包屑 */
.breadcrumb{display:flex;align-items:center;gap:8px;margin:16px 0 0;font-size:.82rem;flex-wrap:wrap;padding:8px 0;color:var(--t3)}
.breadcrumb a{color:var(--t2);text-decoration:none;padding:3px 8px;border-radius:6px;transition:all .2s;font-weight:500}
.breadcrumb a:hover{color:var(--b);background:#eef2ff}
.breadcrumb a.current{color:var(--b);font-weight:600}
.breadcrumb .sep{color:var(--t3);font-size:.7rem}

/* 多级分类菜单 */
.cat-nav{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 0;position:relative}
.cat-group{position:relative}
.cat-pill{display:inline-flex;align-items:center;gap:4px;font-size:.8rem;padding:6px 16px;border-radius:40px;background:var(--card);color:var(--t2);border:1px solid #e2e8f0;text-decoration:none;transition:all .2s;cursor:pointer;white-space:nowrap;font-weight:500}
.cat-pill:hover{color:var(--b);border-color:var(--b);background:#eef2ff;box-shadow:0 2px 8px rgba(37,99,235,.08)}
.cat-pill.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;border-color:transparent;box-shadow:0 2px 8px rgba(37,99,235,.2)}
.cat-pill.active .cat-count{color:rgba(255,255,255,.75)}
.cat-count{font-size:.7rem;color:var(--t3);opacity:.8}
.arrow{font-size:.55rem;opacity:.5;transition:transform .2s}
.cat-group:hover>.cat-pill .arrow{transform:rotate(180deg)}

/* 下拉子菜单 */
.cat-dropdown{display:none;position:absolute;top:100%;left:0;min-width:170px;background:#fff;border:1px solid #e2e8f0;border-radius:var(--radius-md);box-shadow:var(--sm);padding:6px;z-index:100;margin-top:6px}
.cat-group:hover>.cat-dropdown{display:block;animation:fadeIn .15s ease}
.cat-dropdown .cat-group{display:block}
.cat-dropdown .cat-pill{display:flex;width:100%;border-radius:8px;border:none;padding:7px 12px;font-size:.8rem;background:transparent}
.cat-dropdown .cat-pill:hover{background:#f0f4ff}
.cat-dropdown .cat-pill.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff}
.cat-dropdown .cat-dropdown{position:static;box-shadow:none;border:none;padding:0 0 0 16px;margin-top:2px}
.cat-dropdown .cat-dropdown .cat-pill{font-size:.76rem;padding:5px 12px}
@keyframes fadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}

/* 移动端适配 */
@media(max-width:600px){
  .cat-nav{overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch}
  .cat-dropdown{position:static;box-shadow:none;border:1px solid #e2e8f0;margin-top:4px;border-radius:var(--radius-sm)}
}
.empty{padding:60px 20px;text-align:center;color:var(--t3)}.empty .empty-icon{font-size:3rem;margin-bottom:8px}
.empty p{font-size:.9rem}
.loading{text-align:center;padding:40px;color:var(--t3)}
.loading .spinner{display:inline-block;width:26px;height:26px;border:3px solid #e2e8f0;border-top-color:var(--b);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.pagination{display:flex;justify-content:center;gap:6px;padding:20px 0 36px}
.pagination button{width:36px;height:36px;border-radius:50%;border:1px solid #e2e8f0;background:var(--card);color:var(--t1);font-size:.85rem;cursor:pointer;font-weight:500;transition:all .2s}
.pagination button:hover,.pagination button.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;border-color:transparent;box-shadow:0 2px 8px rgba(37,99,235,.2)}
.pagination button:disabled{opacity:.4;cursor:default}
.admin-wrap{max-width:1000px;margin:0 auto;padding:20px 0;width:100%}
.admin-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.admin-header h1{font-size:1.4rem;font-weight:700;letter-spacing:-.3px}.btn{padding:9px 22px;border-radius:40px;border:none;font-weight:600;cursor:pointer;font-size:.84rem;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.2)}.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,.3)}
.btn-secondary{background:#f1f4f9;color:var(--t1)}.btn-secondary:hover{background:#e2e8f0}
.btn-danger{background:#ef4444;color:#fff}.btn-danger:hover{background:#dc2626}
.admin-table{width:100%;border-collapse:separate;border-spacing:0;background:var(--card);border-radius:var(--radius-md);overflow:hidden;box-shadow:var(--s)}
.admin-table th,.admin-table td{padding:14px 18px;text-align:left;font-size:.85rem;border-bottom:1px solid rgba(0,0,0,.04)}
.admin-table th{background:#f8faff;font-weight:600;color:var(--t2);font-size:.78rem;text-transform:uppercase;letter-spacing:.6px}
.admin-table td .actions{display:flex;gap:4px}.admin-table td .actions button{padding:5px 12px;border-radius:8px;border:none;font-size:.77rem;cursor:pointer;transition:all .2s}
.admin-table td .actions button:hover{transform:translateY(-1px)}
.admin-table tr:last-child td{border-bottom:none}
.admin-table tr:hover td{background:#fafbff}.status-badge{display:inline-block;padding:3px 10px;border-radius:40px;font-size:.7rem;font-weight:600;white-space:nowrap}
.file-link{color:var(--b);text-decoration:none;cursor:pointer;border-bottom:1px dashed rgba(99,102,241,.4);transition:all .15s}
.file-link:hover{color:var(--b);text-decoration:underline;border-bottom-style:solid}
.status-badge.published{background:#dcfce7;color:#166534}.status-badge.draft{background:#fef3c7;color:#92400e}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.modal.show{display:flex}.modal-content{background:var(--card);border-radius:var(--radius-lg);padding:32px;width:100%;max-width:880px;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,.2)}
.modal-content h2{font-size:1.2rem;margin-bottom:18px;font-weight:700;letter-spacing:-.3px}.modal-content label{display:block;font-size:.82rem;font-weight:600;color:var(--t2);margin-bottom:5px;margin-top:14px}
.modal-content input,.modal-content select,.modal-content textarea{width:100%;padding:11px 16px;border:1px solid #e2e8f0;border-radius:var(--radius-sm);font-size:.9rem;outline:none;margin-bottom:4px;font-family:inherit;transition:all .2s}
.modal-content input:focus,.modal-content select:focus,.modal-content textarea:focus{border-color:var(--b);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.modal-content textarea{min-height:200px;resize:vertical;font-family:ui-monospace,monospace;font-size:.85rem;line-height:1.6}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;margin:18px -32px -32px;padding:14px 32px 24px;border-top:1px solid rgba(0,0,0,.06);background:var(--card);z-index:5}
.modal-actions button{padding:9px 26px;border-radius:40px;border:none;font-weight:600;cursor:pointer;font-size:.85rem;transition:all .2s}
.modal-actions button:hover{transform:translateY(-1px)}
.form-row{display:flex;gap:14px}.form-row>*{flex:1}.form-hint{font-size:.75rem;color:var(--t3);margin-top:2px}
.editor-toolbar button:hover{background:linear-gradient(135deg,var(--g1),var(--g2))!important;color:#fff!important;border-color:transparent!important}
.content pre{background:#f6f8fa;color:#24292f;padding:16px;border-radius:var(--radius-sm);overflow-x:auto;font-size:1.05rem;line-height:1.7;margin:16px 0;border:1px solid rgba(0,0,0,.08);position:relative}
.content code{background:rgba(110,118,129,.2);color:#ffa657;padding:2px 8px;border-radius:6px;font-size:.92em;font-family:ui-monospace,monospace;font-weight:500}
.content pre code{background:transparent;padding:0;border-radius:0;color:#24292f;font-weight:400}
.copy-btn{position:absolute;top:8px;right:8px;padding:5px 12px;font-size:.7rem;background:#21262d;color:#8b949e;border:none;border-radius:6px;cursor:pointer;opacity:0;transition:opacity .2s;font-family:'Inter',system-ui;z-index:10;font-weight:500}
.copy-btn:hover{background:#30363d;color:#e6edf3}
.content pre:hover .copy-btn{opacity:1}
.copy-btn.copied{background:#1a7f37;color:#fff}
/* Editor.md 样式 */
.editormd{width:100%!important;border:1px solid #e2e8f0!important;border-radius:var(--radius-sm)!important;overflow:hidden!important}
.editormd .editormd-toolbar{background:var(--card)!important;border-bottom:1px solid #e2e8f0!important;border-radius:var(--radius-sm) var(--radius-sm) 0 0!important}
.editormd .editormd-menu li a{color:var(--t1)!important}
.editormd .editormd-menu li a:hover{background:#f1f4f9!important;border-radius:4px!important}
.editormd .editormd-preview{background:var(--card)!important}
.editormd .editormd-preview-container{padding:20px!important}
.editormd .editormd-preview-close-btn{top:66px!important;right:16px!important;z-index:21!important;background:rgba(37,99,235,.92)!important;padding:3px 9px!important;font-size:15px!important}
.editormd .editormd-preview-close-btn:hover{background:#2563eb!important}
.editormd .editormd-preview-container img{max-width:100%;border-radius:var(--radius-sm);margin:8px 0;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.editormd .editormd-preview-container pre{background:#f8fafc!important;border-radius:var(--radius-sm);padding:14px;border:1px solid #e2e8f0}
.editormd .editormd-preview-container code{background:#f1f4f9;padding:2px 6px;border-radius:4px;font-size:.85em}
.editormd .CodeMirror{min-height:380px!important;max-height:70vh!important}
.editormd-editormd{min-height:420px!important}
@media(max-width:640px){.header-inner{flex-wrap:wrap;padding:0 14px;height:auto;min-height:54px;gap:6px}.single-post{padding:20px;margin:14px 0}.single-post h1{font-size:1.3rem}.post-card{flex-direction:column;padding:0;border-radius:var(--radius-sm);overflow:hidden;gap:0}.post-thumb{width:100%;height:160px;flex:none;border-radius:0}.post-body{padding:18px}.modal-content{padding:20px}.form-row{flex-direction:column;gap:0}.search-bar{flex-direction:column}.search-bar button{width:100%}}

/* 评论样式 */
.comments{ margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(0,0,0,.06); }
.comment{ background:#f8fafc; border-radius:12px; padding:14px 16px; margin-bottom:10px; }
.comment strong{ color:var(--t1); }
.comment time{ font-size:.75rem; color:var(--t3); margin-left:10px; }
.comment p{ margin-top:6px; font-size:.9rem; line-height:1.6; color:var(--t2); }
#commentForm{ display:grid; gap:8px; margin-top:16px; }
#commentForm input,
#commentForm textarea{
    width:100%; padding:10px 14px;
    border:1px solid #e2e8f0; border-radius:10px;
    font-size:.9rem; outline:none;
}
#commentForm textarea{ min-height:80px; resize:vertical; }
#commentForm button{
    justify-self:end; padding:9px 22px;
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    color:#fff; border:none; border-radius:40px;
    font-weight:600; cursor:pointer;
}

/* 统计卡片 */
.stats-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:24px; }
.stat-card{
    background:var(--card);
    border-radius:14px;
    padding:20px;
    text-align:center;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.stat-card b{ font-size:1.8rem; display:block; color:var(--b); }
.stat-card span{ font-size:.8rem; color:var(--t3); }

/* ========== 全新暗色模式 ========== */
.dark{
    --bg: #262c36;
    --bg-header: rgba(38,44,54,.88);
    --card: #303744;
    --card-hover: #39424f;
    --t1: #eef2f8;
    --t2: #cdd6e3;
    --t3: #a4aec0;
    --border: rgba(255,255,255,.12);
    --shadow: 0 1px 3px rgba(0,0,0,.3);
    --shadow-lg: 0 8px 24px rgba(0,0,0,.3);
    --input-bg: #262c36;
    --code-bg: #3a4350;
    --quote-bg: rgba(153,153,255,.12);
    --table-th: #3a4350;
    --table-hover: #3a4350;
    --btn-secondary: #3d4654;
    --btn-secondary-h: #475161;
    --modal-backdrop: rgba(0,0,0,.45);
}
.dark body{ background-image: radial-gradient(ellipse at 50% 0%, rgba(56,139,253,.08) 0%, transparent 60%); }
.dark header{ background: var(--bg-header); border-bottom-color: var(--border); }
.dark footer{ background: rgba(38,44,54,.88); border-top-color: var(--border); color: var(--t3); }
.dark .post-card,.dark .single-post,.dark .admin-table,.dark .modal-content,.dark .stat-card{ background: var(--card); border-color: var(--border); box-shadow: var(--shadow); }
.dark .post-card:hover{ box-shadow: var(--shadow-lg); border-color: rgba(56,139,253,.3); }
.dark .post-card::before{ background: linear-gradient(90deg, #2563eb, #7c3aed); }
.dark .post-card h2 a,.dark .single-post h1,.dark .single-post .content{ color: var(--t1); }
.dark .post-card .excerpt,.dark .single-post .content p,.dark .single-post .content li{ color: var(--t2); }
.dark .post-card .meta,.dark .single-post .meta{ color: var(--t3); }
.dark .cat-pill{ background: var(--card); border-color: var(--border); color: var(--t2); }
.dark .cat-pill:hover{ background: var(--card-hover); color: #58a6ff; border-color: #58a6ff; }
.dark .cat-pill.active{ background: linear-gradient(135deg, #2563eb, #7c3aed); color: #fff; border-color: transparent; }
.dark .cat-count{ color: var(--t3); }
.dark .cat-dropdown{ background: var(--card); border-color: var(--border); box-shadow: var(--shadow-lg); }
.dark .cat-dropdown .cat-pill:hover{ background: var(--card-hover); }
.dark .breadcrumb a{ color: var(--t2); }
.dark .breadcrumb a:hover{ color: #58a6ff; background: rgba(56,139,253,.1); }
.dark .breadcrumb a.current{ color: #58a6ff; }
.dark .search-bar input{ background: var(--input-bg); border-color: var(--border); color: var(--t1); }
.dark .search-bar input::placeholder{ color: var(--t3); }
.dark .search-bar input:focus{ border-color: #58a6ff; box-shadow: 0 0 0 3px rgba(56,139,253,.15); }
.dark .search-bar button{ background: linear-gradient(135deg, #2563eb, #7c3aed); box-shadow: 0 2px 8px rgba(37,99,235,.3); }
.dark .pagination button{ background: var(--card); border-color: var(--border); color: var(--t1); }
.dark .pagination button:hover,.dark .pagination button.active{ background: linear-gradient(135deg, #2563eb, #7c3aed); color: #fff; border-color: transparent; box-shadow: 0 2px 8px rgba(37,99,235,.2); }
.dark .pagination button:disabled{ opacity: .4; }
.dark .admin-table th{ background: var(--table-th); color: var(--t2); border-bottom-color: var(--border); }
.dark .admin-table td{ border-bottom-color: var(--border); color: var(--t2); }
.dark .admin-table tr:hover td{ background: var(--table-hover); }
.dark .btn-secondary{ background: var(--btn-secondary); color: var(--t1); }
.dark .btn-secondary:hover{ background: var(--btn-secondary-h); }
.dark .btn-danger{ background: #f85149; }
.dark .btn-danger:hover{ background: #da3633; }
.dark .modal{ background: var(--modal-backdrop); }
.dark .modal-content{ box-shadow: 0 25px 50px rgba(0,0,0,.5); }
.dark input,.dark select,.dark textarea{ background: var(--input-bg); border-color: var(--border); color: var(--t1); }
.dark input:focus,.dark select:focus,.dark textarea:focus{ border-color: #58a6ff; box-shadow: 0 0 0 3px rgba(56,139,253,.15); }
.dark input::placeholder,.dark textarea::placeholder{ color: var(--t3); }
.dark .modal-content label{ color: var(--t2); }
.dark .form-hint{ color: var(--t3); }
.dark .comment{ background: var(--code-bg); border: 1px solid var(--border); }
.dark .comment strong{ color: var(--t1); }
.dark .comment time{ color: var(--t3); }
.dark .comment p{ color: var(--t2); }
.dark #commentForm input,.dark #commentForm textarea{ background: var(--input-bg); border-color: var(--border); color: var(--t1); }
.dark .single-post .content blockquote{ background: var(--quote-bg); border-left-color: #58a6ff; color: var(--t2); }
.dark .single-post .content code{ background: var(--code-bg); color: #ffa657; }
.dark .single-post .content pre{ background: #3a4350; border-color: var(--border); }
.dark .single-post .content pre code{ color: #e6edf3; }
.dark .single-post .content a{ color: #58a6ff; }
.dark .copy-btn{ background: #21262d; color: #8b949e; }
.dark .copy-btn:hover{ background: #30363d; color: #e6edf3; }
.dark .copy-btn.copied{ background: #1a7f37; color: #fff; }
.dark .status-badge.published{ background: #1a7f37; color: #fff; }
.dark .status-badge.draft{ background: #9e6a03; color: #fff; }
.dark .empty{ color: var(--t3); }
.dark .loading{ color: var(--t3); }
.dark .loading .spinner{ border-color: #30363d; border-top-color: #58a6ff; }
.dark .editormd{ border-color: var(--border) !important; background: var(--card) !important; }
.dark .editormd .editormd-toolbar{ background: var(--card) !important; border-bottom-color: var(--border) !important; }
.dark .editormd .editormd-menu li a{ color: var(--t2) !important; }
.dark .editormd .editormd-menu li a:hover{ background: var(--card-hover) !important; }
.dark .editormd .editormd-preview{ background: var(--card) !important; color: var(--t1); }
.dark .editormd .editormd-preview-container{ color: var(--t1); }
.dark .editormd .editormd-preview-container blockquote{ background: var(--quote-bg); border-left-color: #58a6ff; }
.dark .editormd .editormd-preview-container code{ background: var(--code-bg); color: #ffa657; }
.dark .editormd .editormd-preview-container pre{ background: #3a4350; border-color: var(--border); }
.dark .editormd .CodeMirror{ background: var(--card) !important; color: var(--t1) !important; border-color: var(--border) !important; }
.dark .editormd .CodeMirror-cursor{ border-left-color: #58a6ff !important; }
.dark .editormd .CodeMirror-selected{ background: rgba(56,139,253,.2) !important; }
.dark .editormd .CodeMirror-gutters{ background: var(--card) !important; border-right-color: var(--border) !important; }
.dark .editormd .CodeMirror-linenumber{ color: var(--t3) !important; }
.dark .editormd .CodeMirror-matchingbracket{ color: #58a6ff !important; }
.dark .editor-toolbar .btn-upload{ background: var(--card) !important; border-color: var(--border) !important; color: var(--t2) !important; }
.dark .editor-toolbar .btn-upload:hover{ background: var(--card-hover) !important; color: #58a6ff !important; }
.dark ::-webkit-scrollbar{ width: 10px; height: 10px; }
.dark ::-webkit-scrollbar-track{ background: var(--bg); }
.dark ::-webkit-scrollbar-thumb{ background: #30363d; border-radius: 5px; }
.dark ::-webkit-scrollbar-thumb:hover{ background: #484f58; }
@media(max-width:600px){ .dark .cat-dropdown{ background: var(--card); border-color: var(--border); } }

/* ===== Blogmate 风格 ===== */
body{
  background: linear-gradient(135deg, #f4f7fb 0%, #eef2ff 50%, #fdf4ff 100%);
  background-size: 400% 400%;
  animation: bgShift 18s ease infinite;
}
@keyframes bgShift{
  0%  { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100%{ background-position: 0% 50%; }
}
.container{ max-width: 1200px; }
header{ background: rgba(255,255,255,.92); }
.header-inner{
  flex-direction: column;
  height: auto;
  padding: 26px 20px 16px;
  gap: 12px;
}
.logo{ font-size: 2.1rem; letter-spacing: -.6px; }
header nav{ flex-wrap: wrap; justify-content: center; gap: 6px; }
.posts{ gap: 18px; padding: 28px 0; }
.post-card{
  display: flex;
  align-items: stretch;
  gap: 20px;
  padding: 18px;
  border-radius: 14px;
  background: #fff;
  border: 1px solid rgba(0,0,0,.05);
  box-shadow: 0 4px 18px rgba(0,0,0,.06);
}
.post-thumb{
  flex: 0 0 130px;
  width: 130px;
  min-height: 130px;
  border-radius: 10px;
  background: linear-gradient(135deg, #6366f1, #a855f7);
  overflow: hidden;
  position: relative;
  display: block;
  text-decoration: none;
  cursor: pointer;
}
.post-thumb img{ width:100%; height:100%; object-fit:cover; display:block; position:absolute; top:0; left:0; }
.post-thumb .thumb-letter{ display:flex; align-items:center; justify-content:center; width:100%; height:100%; color:#fff; font-size:2.4rem; font-weight:800; }
.post-body{ flex: 1; display: flex; flex-direction: column; justify-content: center; min-width: 0; }
.post-body .cat{ margin-bottom: 8px; }
.post-body h2{ font-size: 1.45rem; margin-bottom: 10px; line-height: 1.4; }
.post-body .meta{ margin-bottom: 12px; }
.post-body .excerpt{ margin-bottom: 12px; }
.dark body{
    background: linear-gradient(135deg, #262c36 0%, #303744 50%, #262c36 100%);
  background-size: 400% 400%;
  animation: bgShift 18s ease infinite;
}
.dark header{ background: rgba(13,17,23,.92); }
.dark .post-card{ background: #161b22; border-color: var(--border); }
.dark .post-thumb{ background: linear-gradient(135deg, #4f46e5, #9333ea); }
.tags-bar{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 0}
.tag-pill,.tag-chip{display:inline-flex;align-items:center;gap:4px;font-size:.78rem;padding:4px 12px;border-radius:40px;background:var(--card);color:var(--t2);border:1px solid #e2e8f0;text-decoration:none;transition:all .2s}
.tag-pill.active,.tag-pill:hover,.tag-chip:hover,.tag-chip.active{background:var(--b);color:#fff;border-color:var(--b)}
.tag-count{font-size:.68rem;opacity:.75}
.tag-title{font-size:.95rem;font-weight:700;color:var(--t1);margin-top:18px}
.card-tags,.post-tags{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px}
.toc-box{background:var(--card);border:1px solid rgba(0,0,0,.05);border-radius:10px;padding:12px 16px;margin:14px 0;font-size:.85rem;color:var(--t2);display:none}
.toc-box.show{display:block}
.toc-box a{color:var(--t2);text-decoration:none;display:block;padding:2px 0}
.toc-box a:hover{color:var(--b)}
.toc-box .l2{padding-left:16px}.toc-box .l3{padding-left:32px}
#readingBar{position:fixed;top:0;left:0;height:3px;background:linear-gradient(90deg,#2563eb,#7c3aed);width:0;z-index:9999;transition:width .1s linear}
.reply{margin-left:28px;margin-top:8px}
.comment-children{margin-left:16px;border-left:2px solid rgba(0,0,0,.05);padding-left:8px}
.reply-btn{background:none;border:none;color:var(--b);font-size:.72rem;cursor:pointer;padding:2px 0}
.related-posts{margin-top:28px;padding-top:16px;border-top:1px solid rgba(0,0,0,.06)}
.related-posts h3{font-size:1rem;margin-bottom:10px}
.related-posts li{margin-bottom:6px;font-size:.9rem}
.related-posts a{color:var(--b);text-decoration:none}
@media print{header,footer,.comments,.related-posts,.nav-links,.toc-box,#readingBar,.theme-btn,.admin-wrap{display:none!important}.single-post{box-shadow:none;border:none;margin:0;padding:0}.container{max-width:100%}}
.dark .tag-pill,.dark .tag-chip{background:var(--card);border-color:var(--border);color:var(--t2)}
.dark .toc-box{background:var(--card);border-color:var(--border)}
.hot-searches{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 0;font-size:.75rem;align-items:center}
.hot-label{color:var(--t3)}
.hot-link{color:var(--b);cursor:pointer;padding:2px 8px;border-radius:20px;background:#eef2ff}
.hot-link:hover{background:var(--b);color:#fff}
.lock-tip{font-size:.85rem;color:var(--t3);margin:8px 0}
.content mark{background:#fde68a;color:#78350f;padding:0 2px;border-radius:3px}
.toc-box a.active{color:var(--b)!important;font-weight:600}
#lightbox{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:2000;display:none;align-items:center;justify-content:center;padding:20px;cursor:zoom-out}
#lightbox.show{display:flex}
#lightbox img{max-width:92vw;max-height:88vh;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.5)}
.batch-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;font-size:.8rem}
.batch-bar button{padding:5px 12px;border-radius:8px;border:1px solid #e2e8f0;background:var(--card);color:var(--t1);cursor:pointer}
.batch-bar button:hover{background:var(--b);color:#fff;border-color:var(--b)}
.dark .batch-bar button{background:var(--card);border-color:var(--border);color:var(--t2)}
.quota-bar{height:8px;background:#eef2ff;border-radius:6px;overflow:hidden;margin-top:4px}
.quota-bar>div{height:100%;background:linear-gradient(90deg,#2563eb,#7c3aed)}
.quota-bar.indeterminate>div{width:40%;animation:backupSlide 1.2s ease-in-out infinite}
@keyframes backupSlide{0%{margin-left:-40%}100%{margin-left:100%}}
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}
.settings-col{display:flex;flex-direction:column;gap:18px;min-width:0}
.settings-card{background:var(--card);border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:24px;box-shadow:var(--s)}
.settings-card h2{font-size:1.02rem;font-weight:700;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid rgba(0,0,0,.06);display:flex;align-items:center;gap:8px}
.settings-card .settings-sub{font-size:.9rem;font-weight:700;margin:18px 0 4px;padding-top:14px;border-top:1px solid rgba(0,0,0,.06);color:var(--t1)}
.settings-card label{display:block;font-size:.82rem;font-weight:600;color:var(--t2);margin:12px 0 6px}
.settings-card input,.settings-card textarea{width:100%;box-sizing:border-box;padding:10px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:.9rem;outline:none;background:#fff;color:var(--t1);transition:border-color .2s,box-shadow .2s}
.settings-card input:focus,.settings-card textarea:focus{border-color:var(--b);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.settings-card .hint{font-size:.75rem;color:var(--t3);margin-top:4px}
.settings-card .btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:40px;border:none;font-weight:600;font-size:.84rem;cursor:pointer;transition:all .2s}
.settings-card .btn-primary{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.2)}
.settings-card .btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,.3)}
.settings-card .btn-secondary{background:#f1f4f9;color:var(--t1);border:1px solid #e2e8f0}
.settings-card .btn-secondary:hover{background:#e2e8f0}
.dark .settings-card{background:var(--card);border-color:var(--border)}
.dark .settings-card h2{border-bottom-color:var(--border)}
.dark .settings-card .settings-sub{border-top-color:var(--border)}
.dark .settings-card input,.dark .settings-card textarea{background:var(--input-bg);border-color:var(--border);color:var(--t1)}
.dark .settings-card .btn-secondary{background:var(--btn-secondary);color:var(--t1);border-color:var(--border)}
/* ========== Joe3 风格主题覆盖（2026-08-02） ========== */
:root{--b:#fb6c28;--bh:#e85c1a;--g1:#ff8a3d;--g2:#ff4e6b}
.dark{--b:#9999ff;--bh:#7c7cf5;--g1:#a78bfa;--g2:#6366f1}
body{background:#f0f2f5;background-image:radial-gradient(ellipse at 50% 0%,rgba(251,108,40,.05) 0%,transparent 60%)}
header{background:rgba(255,255,255,.82);box-shadow:0 1px 10px rgba(0,0,0,.05)}
.header-inner{display:flex;align-items:center;justify-content:flex-start;flex-direction:row;height:66px;padding:0 20px;gap:16px;max-width:1200px;margin:0 auto}
.logo{display:inline-flex;align-items:center;flex:none;white-space:nowrap;font-size:1.75rem;font-weight:800;letter-spacing:-.3px}
.logo .logo-badge{flex:none;margin-left:9px;filter:drop-shadow(0 2px 5px rgba(0,0,0,.18))}
header nav{display:flex;align-items:center;flex-wrap:nowrap;justify-content:flex-end;gap:6px;margin-left:auto}
header nav a,.theme-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:40px;padding:0 15px;border-radius:9px;color:var(--t2);font-size:.95rem;font-weight:500;line-height:1;white-space:nowrap;border:1px solid transparent}
header nav a:hover{color:var(--b);background:rgba(251,108,40,.08)}
header nav a.active{color:var(--b);background:rgba(251,108,40,.1);box-shadow:none;font-weight:600}
header nav a.active::after{display:none}
.theme-btn{width:36px;padding:0;border-color:rgba(0,0,0,.08);background:var(--card);border-radius:50%;font-size:.95rem;cursor:pointer}
.theme-btn:hover{color:var(--b);background:rgba(251,108,40,.08)}
header nav .nav-btn{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;border-color:transparent;font-weight:600;box-shadow:0 2px 8px rgba(251,108,40,.22)}
header nav .nav-btn:hover{background:linear-gradient(135deg,var(--bh),var(--g2));color:#fff;transform:translateY(-1px)}
header nav .nav-btn.active{background:linear-gradient(135deg,var(--bh),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(251,108,40,.25)}
.container.home-layout{max-width:1200px}
/* 管理面板标题与标签 */
.admin-header h1{font-size:1.5rem;font-weight:800;letter-spacing:-.3px;background:linear-gradient(135deg,var(--g1),var(--g2));-webkit-background-clip:text;background-clip:text;color:transparent}
.admin-header .tab-btn{background:#f1f4f9;color:var(--b);border:1px solid transparent}
.admin-header .tab-btn:hover{background:#e2e8f0;transform:translateY(-1px);color:var(--bh)}
.admin-header .tab-btn.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 10px rgba(251,108,40,.28)}
.file-summary{color:#334155;font-size:.85rem;font-weight:500}
.dark .file-summary{color:#e2e8f0}
.back-home{color:var(--b);text-decoration:none;font-size:.85rem;font-weight:500;transition:color .2s}
.back-home:hover{color:var(--bh)}
.dark .back-home{color:#9999ff}
.dark .back-home:hover{color:#b3b3ff}
.dark .admin-header h1{background:linear-gradient(135deg,var(--g1),var(--g2));-webkit-background-clip:text;background-clip:text;color:transparent;text-shadow:0 0 16px rgba(153,153,255,.35)}
.dark .admin-header .tab-btn{background:var(--btn-secondary);color:#9999ff;border-color:var(--border)}
.dark .admin-header .tab-btn:hover{background:var(--btn-secondary-h);color:#b3b3ff}
.dark .admin-header .tab-btn.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 10px rgba(153,153,255,.3)}
/* 首页双栏布局 */
.home-layout{display:flex;gap:20px;align-items:flex-start;padding:24px 0}
.home-main{flex:1;min-width:0}
.home-main .search-bar{margin-top:0}
.home-main .posts{padding:16px 0 8px}
.home-aside{width:280px;flex:none;display:flex;flex-direction:column;gap:16px;position:sticky;top:76px}
.aside-card{background:var(--card);border:1px solid rgba(0,0,0,.05);border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06);overflow:hidden}
.aside-title{display:flex;align-items:center;gap:8px;font-size:.95rem;font-weight:700;color:var(--t1);padding:14px 16px;border-bottom:1px solid rgba(0,0,0,.05)}
.aside-title::after{content:'';flex:1;height:1px;background:rgba(0,0,0,.05)}
.author-card{padding:26px 16px 18px;text-align:center;background:linear-gradient(160deg,#fff5ef,#ffe9ef)}
.author-card .avatar{width:72px;height:72px;margin:0 auto 12px;border-radius:50%;background:#fff;border:3px solid #ffd9c7;display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:#fb6c28}
.author-card .avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block}
.author-card .name{font-size:1.15rem;font-weight:800;color:#9a3412;margin-bottom:12px}
.author-card .bio{font-size:.78rem;color:#b45309;line-height:1.5;margin:-6px 0 12px;padding:0 6px}
.author-card .stats{display:flex;justify-content:center;background:rgba(251,108,40,.08);border-radius:10px;padding:10px 0}
.author-card .stats span{flex:1;font-size:.72rem;color:#b45309;display:flex;flex-direction:column;gap:2px}
.author-card .stats b{font-size:1rem;color:#c2410c}
.aside-list{padding:6px 0}
.aside-cat{display:flex;align-items:center;justify-content:space-between;padding:9px 16px;font-size:.86rem;color:var(--t2);text-decoration:none;transition:all .2s}
.aside-cat:hover{color:var(--b);background:rgba(251,108,40,.06);padding-left:20px}
.aside-cat em{font-style:normal;font-size:.72rem;color:var(--t3);background:rgba(0,0,0,.05);border-radius:10px;padding:1px 8px}
.aside-tags{padding:14px 16px;display:flex;gap:6px;flex-wrap:wrap}
.aside-tags a{font-size:.75rem;padding:4px 11px;border-radius:40px;background:rgba(251,108,40,.08);color:var(--b);text-decoration:none;transition:all .2s}
.aside-tags a:hover,.aside-tags a.active{background:var(--b);color:#fff}
.latest-toggle{display:block;text-align:center;font-size:.75rem;color:var(--b);padding:9px 0 2px;margin-top:4px;border-top:1px dashed rgba(0,0,0,.08);cursor:pointer;text-decoration:none;user-select:none;transition:color .2s}
.latest-toggle:hover{color:var(--bh)}
/* 首页卡片（参考站横向卡片） */
.posts{gap:18px;padding:18px 0 6px}
.post-card{display:flex;align-items:stretch;gap:20px;padding:18px;border-radius:14px;background:var(--card);border:1px solid rgba(0,0,0,.05);box-shadow:0 4px 18px rgba(0,0,0,.06);transition:all .3s}
.post-card:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(0,0,0,.1)}
.post-card:hover{border-color:rgba(251,108,40,.18)}
.post-card::before{background:linear-gradient(90deg,var(--g1),var(--g2))}
.post-thumb{flex:0 0 220px;width:220px;min-height:0;aspect-ratio:16/10;height:auto;align-self:center;border-radius:10px;background:linear-gradient(135deg,var(--g1),var(--g2));overflow:hidden;position:relative;display:block;text-decoration:none}
.post-thumb img{width:100%;height:100%;object-fit:cover;display:block;position:absolute;top:0;left:0}
.post-thumb svg{width:100%;height:100%;display:block;position:absolute;top:0;left:0}
.post-thumb time{position:absolute;left:10px;bottom:10px;font-size:.7rem;color:#fff;background:rgba(0,0,0,.55);border-radius:6px;padding:2px 8px;z-index:2}
.post-body{flex:1;display:flex;flex-direction:column;justify-content:center;min-width:0}
.post-body .cat{margin-bottom:8px}
.post-body h2{font-size:1.25rem;margin-bottom:8px;line-height:1.4}
.post-body .excerpt{margin-bottom:12px}
.post-body .meta{margin:0 0 10px}
.post-body .read-more{margin-top:2px}
/* 文章页（参考站风格） */
.breadcrumb{margin:18px 0 0}
.single-post{margin:18px 0 28px}
.single-post .cat{display:table;margin:0 auto 14px}
.single-post h1{text-align:center;font-size:2rem;margin-bottom:16px}
.single-post .meta{justify-content:center;border-bottom:1px solid rgba(0,0,0,.06);padding-bottom:20px}
.single-post .joe_post__pagination-item a{box-shadow:0 2px 8px rgba(251,108,40,.25)}
/* 暗色模式适配 */
.dark body{background-image:linear-gradient(135deg,#262c36 0%,#303744 50%,#262c36 100%),radial-gradient(ellipse at 50% 0%,rgba(153,153,255,.08) 0%,transparent 60%)}
.dark header{background:var(--bg-header);box-shadow:0 1px 10px rgba(0,0,0,.3)}
.dark header nav a:hover{background:rgba(153,153,255,.12);color:#9999ff}
.dark header nav a.active{color:#9999ff;background:rgba(153,153,255,.12)}
.dark header nav a.active::after{background:#9999ff}
.dark .theme-btn{border-color:var(--border);background:var(--card)}
.dark header nav .nav-btn{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(153,153,255,.25)}
.dark header nav .nav-btn:hover,.dark header nav .nav-btn.active{background:linear-gradient(135deg,var(--bh),var(--g2));color:#fff}
.dark .aside-card{background:var(--card);border-color:var(--border);box-shadow:0 4px 18px rgba(0,0,0,.3)}
.dark .aside-title{border-bottom-color:var(--border)}
.dark .aside-title::after{background:var(--border)}
.dark .aside-cat em{background:rgba(255,255,255,.08);color:var(--t3)}
.dark .aside-tags a{background:rgba(153,153,255,.15);color:#9999ff}
.dark .aside-tags a:hover,.dark .aside-tags a.active{background:#9999ff;color:#0d1117}
.dark .post-card{background:var(--card);border-color:var(--border)}
.dark .post-card::before{background:linear-gradient(90deg,var(--g1),var(--g2))}
.dark .post-thumb{background:linear-gradient(135deg,var(--g1),var(--g2))}
.dark .author-card{background:linear-gradient(160deg,var(--g1),var(--g2))}
.dark .author-card .avatar{background:rgba(255,255,255,.25);border:3px solid rgba(255,255,255,.7);color:#fff}
.dark .author-card .name{color:#fff}
.dark .author-card .bio{color:rgba(255,255,255,.92)}
.dark .author-card .stats{background:rgba(0,0,0,.14)}
.dark .author-card .stats span{color:rgba(255,255,255,.85)}
.dark .author-card .stats b{color:#fff}
.dark .single-post .cat{background:rgba(153,153,255,.15);color:#9999ff}
.dark .breadcrumb a:hover{background:rgba(153,153,255,.12);color:#9999ff}
.dark .breadcrumb a.current{color:#9999ff}
/* 移动端适配 */
@media(max-width:768px){
  .home-layout{flex-direction:column;padding:14px 0}
  .home-aside{width:100%;position:static}
  .header-inner{flex-wrap:wrap;height:auto;padding:12px 14px;gap:8px}
  header nav{margin-left:0}
  .post-card{flex-direction:column;padding:0;border-radius:12px;overflow:hidden;gap:0}
  .post-thumb{width:100%;flex:none;min-height:0;height:auto;aspect-ratio:16/10;border-radius:0}
  .post-body{padding:18px}
.single-post h1{font-size:1.45rem}
}
/* ========== 三主题：暖色（柔和浅杏 → 浅粉） ========== */
.theme-select{height:40px;padding:0 12px;border-radius:9px;border:1px solid rgba(0,0,0,.08);background:var(--card);color:var(--t1);font-size:.9rem;cursor:pointer;font-weight:500;outline:none}
.dark .theme-select,.warm .theme-select{border-color:var(--border);background:var(--card)}
.warm{--bg:#fff5ef;--bg-header:rgba(255,250,245,.98);--card:#fffdfa;--card-hover:#fdeee6;--t1:#7c2d12;--t2:#9a5b3a;--t3:#b98a6d;--border:rgba(124,45,18,.12);--shadow:0 1px 3px rgba(124,45,18,.08);--shadow-lg:0 8px 24px rgba(124,45,18,.12);--input-bg:#fffdfa;--code-bg:#3b2b26;--quote-bg:rgba(251,108,40,.1);--table-th:#fdeee6;--table-hover:#fdf1ea;--btn-secondary:#fdeee6;--btn-secondary-h:#f7e2d6;--modal-backdrop:rgba(124,45,18,.35)}
.warm{--b:#fb6c28;--bh:#e85c1a;--g1:#ff8a3d;--g2:#ff4e6b}
.warm body{background:linear-gradient(135deg,#fff7f0 0%,#ffe9ef 50%,#fff5ef 100%);background-size:400% 400%;animation:bgShift 18s ease infinite}
.warm body{background-image:linear-gradient(135deg,#fff7f0 0%,#ffe9ef 50%,#fff5ef 100%),radial-gradient(ellipse at 50% 0%,rgba(251,108,40,.06) 0%,transparent 60%)}
.warm header{background:var(--bg-header);box-shadow:0 1px 10px rgba(124,45,18,.1)}
.warm footer{background:rgba(255,247,241,.92);border-top-color:var(--border)}
.warm .post-card:hover{border-color:rgba(251,108,40,.3)}
.warm .post-card::before{background:linear-gradient(90deg,var(--g1),var(--g2))}
.warm .cat-pill:hover{color:#fb6c28;border-color:#fb6c28}
.warm .cat-pill.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;border-color:transparent}
.warm .breadcrumb a:hover{color:#fb6c28;background:rgba(251,108,40,.1)}
.warm .breadcrumb a.current{color:#fb6c28}
.warm .search-bar input:focus{border-color:#fb6c28;box-shadow:0 0 0 3px rgba(251,108,40,.15)}
.warm .search-bar button{background:linear-gradient(135deg,var(--g1),var(--g2));box-shadow:0 2px 8px rgba(251,108,40,.3)}
.warm .pagination button:hover,.warm .pagination button.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(251,108,40,.2)}
.warm input:focus,.warm select:focus,.warm textarea:focus{border-color:#fb6c28;box-shadow:0 0 0 3px rgba(251,108,40,.15)}
.warm .single-post .content blockquote{border-left-color:#fb6c28}
.warm .single-post .content a{color:#fb6c28}
.warm .single-post .content pre{background:#fdf0e9;border-color:rgba(124,45,18,.12)}
.warm .editormd .editormd-preview-container pre{background:#fdf0e9;border-color:rgba(124,45,18,.12)}
.warm .editormd .editormd-preview-container blockquote{border-left-color:#fb6c28}
.warm .editormd .CodeMirror-cursor{border-left-color:#fb6c28!important}
.warm .editormd .CodeMirror-selected{background:rgba(251,108,40,.2)!important}
.warm .editormd .CodeMirror-matchingbracket{color:#fb6c28!important}
.warm .editor-toolbar .btn-upload:hover{color:#fb6c28!important}
.warm .copy-btn:hover{background:#4a3a33;color:#e6edf3}
.warm .loading .spinner{border-color:#4a3a33;border-top-color:#fb6c28}
.warm ::-webkit-scrollbar-thumb{background:#4a3a33;border-radius:5px}
.warm ::-webkit-scrollbar-thumb:hover{background:#4a3a33}
.warm .comment{background:var(--card-hover)}
.warm .file-summary{color:#7c2d12}
.warm .back-home{color:#fb6c28}
.warm .back-home:hover{color:#e85c1a}
.warm .admin-header h1{text-shadow:0 0 16px rgba(251,108,40,.3)}
.warm .admin-header .tab-btn{background:var(--btn-secondary);color:#fb6c28;border-color:var(--border)}
.warm .admin-header .tab-btn:hover{background:var(--btn-secondary-h);color:#e85c1a}
.warm .admin-header .tab-btn.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 10px rgba(251,108,40,.3)}
.warm header nav a:hover{background:rgba(251,108,40,.12);color:#fb6c28}
.warm header nav a.active{color:#fb6c28;background:rgba(251,108,40,.1)}
.warm header nav a.active::after{background:#fb6c28}
.warm header nav .nav-btn{position:relative;z-index:2;color:#fff;background:linear-gradient(135deg,#f97316,#e11d48);box-shadow:0 2px 8px rgba(251,108,40,.25);text-shadow:0 1px 2px rgba(0,0,0,.25);font-weight:700}
.warm header nav .nav-btn.active{color:#fff;background:linear-gradient(135deg,#ea580c,#be123c);box-shadow:0 2px 10px rgba(234,88,12,.35)}
.warm .aside-card{box-shadow:0 4px 18px rgba(124,45,18,.1)}
.warm .aside-cat em{background:rgba(124,45,18,.08)}
.warm .aside-tags a{background:rgba(251,108,40,.12);color:#fb6c28}
.warm .aside-tags a:hover,.warm .aside-tags a.active{background:#fb6c28;color:#fff}
.warm .single-post .cat{background:rgba(251,108,40,.12);color:#fb6c28}
/* ========== 四主题：蓝色经典（最早主题） ========== */
.blue{--bg:#f0f4f9;--bg-header:rgba(255,255,255,.92);--card:#fff;--card-hover:#f1f5f9;--t1:#0f172a;--t2:#475569;--t3:#94a3b8;--border:rgba(15,23,42,.08);--shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);--shadow-lg:0 8px 24px rgba(0,0,0,.1);--input-bg:#fff;--code-bg:#1f242c;--quote-bg:rgba(37,99,235,.08);--table-th:#f1f5f9;--table-hover:#f8faff;--btn-secondary:#f1f5f9;--btn-secondary-h:#e2e8f0;--modal-backdrop:rgba(15,23,42,.45)}
.blue{--b:#2563eb;--bh:#1d4ed8;--g1:#2563eb;--g2:#7c3aed}
.blue body{background:linear-gradient(135deg,#f4f7fb 0%,#eef2ff 50%,#fdf4ff 100%);background-size:400% 400%;animation:bgShift 18s ease infinite}
.blue body{background-image:linear-gradient(135deg,#f4f7fb 0%,#eef2ff 50%,#fdf4ff 100%),radial-gradient(ellipse at 50% 0%,rgba(37,99,235,.06) 0%,transparent 60%)}
.blue header{background:var(--bg-header);box-shadow:0 1px 10px rgba(0,0,0,.05)}
.blue footer{background:rgba(255,255,255,.85);border-top-color:var(--border)}
.blue .post-card:hover{border-color:rgba(37,99,235,.25)}
.blue .post-card::before{background:linear-gradient(90deg,var(--g1),var(--g2))}
.blue .cat-pill:hover{color:#2563eb;border-color:#2563eb}
.blue .cat-pill.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;border-color:transparent}
.blue .breadcrumb a:hover{color:#2563eb;background:rgba(37,99,235,.1)}
.blue .breadcrumb a.current{color:#2563eb}
.blue .search-bar input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.blue .search-bar button{background:linear-gradient(135deg,var(--g1),var(--g2));box-shadow:0 2px 8px rgba(37,99,235,.3)}
.blue .pagination button:hover,.blue .pagination button.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.25)}
.blue input:focus,.blue select:focus,.blue textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.blue .single-post .content blockquote{border-left-color:#2563eb}
.blue .single-post .content a{color:#2563eb}
.blue .single-post .content pre{background:#f6f8fa;border-color:rgba(0,0,0,.08)}
.blue .editormd .editormd-preview-container pre{background:#f6f8fa;border-color:rgba(0,0,0,.08)}
.blue .editormd .editormd-preview-container blockquote{border-left-color:#2563eb}
.blue .editormd .CodeMirror-cursor{border-left-color:#2563eb!important}
.blue .editormd .CodeMirror-selected{background:rgba(37,99,235,.2)!important}
.blue .editormd .CodeMirror-matchingbracket{color:#2563eb!important}
.blue .editor-toolbar .btn-upload:hover{color:#2563eb!important}
.blue .copy-btn:hover{background:#30363d;color:#e6edf3}
.blue .loading .spinner{border-color:#30363d;border-top-color:#2563eb}
.blue ::-webkit-scrollbar-thumb{background:#30363d;border-radius:5px}
.blue ::-webkit-scrollbar-thumb:hover{background:#30363d}
.blue .comment{background:var(--card-hover)}
.blue .file-summary{color:#0f172a}
.blue .back-home{color:#2563eb}
.blue .back-home:hover{color:#1d4ed8}
.blue .admin-header h1{text-shadow:0 0 16px rgba(37,99,235,.25)}
.blue .admin-header .tab-btn{background:var(--btn-secondary);color:#2563eb;border-color:var(--border)}
.blue .admin-header .tab-btn:hover{background:var(--btn-secondary-h);color:#1d4ed8}
.blue .admin-header .tab-btn.active{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 10px rgba(37,99,235,.3)}
.blue header nav a:hover{background:rgba(37,99,235,.1);color:#2563eb}
.blue header nav a.active{color:#2563eb;background:rgba(37,99,235,.1)}
.blue header nav a.active::after{background:#2563eb}
.blue header nav .nav-btn{background:linear-gradient(135deg,var(--g1),var(--g2));color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.25);text-shadow:0 1px 2px rgba(0,0,0,.18)}
.blue header nav .nav-btn.active{background:linear-gradient(135deg,var(--bh),var(--g2));color:#fff}
.blue .aside-card{box-shadow:0 4px 18px rgba(0,0,0,.06)}
.blue .aside-cat em{background:rgba(37,99,235,.08)}
.blue .aside-tags a{background:rgba(37,99,235,.1);color:#2563eb}
.blue .aside-tags a:hover,.blue .aside-tags a.active{background:#2563eb;color:#fff}
.blue .single-post .cat{background:rgba(37,99,235,.1);color:#2563eb}
.blue .author-card{background:linear-gradient(160deg,#2563eb,#7c3aed)}
.blue .author-card .avatar{background:rgba(255,255,255,.25);border:3px solid rgba(255,255,255,.7);color:#fff}
.blue .author-card .name{color:#fff}
.blue .author-card .bio{color:rgba(255,255,255,.92)}
.blue .author-card .stats{background:rgba(0,0,0,.14)}
.blue .author-card .stats span{color:rgba(255,255,255,.85)}
.blue .author-card .stats b{color:#fff}
/* 正文图片：桌面端收窄居中（宽度 82%，高度不变，不裁切） */
@media(min-width:768px){
  .single-post .content img,.editormd-preview-container img{max-width:82%!important;margin-left:auto!important;margin-right:auto!important;display:block!important}
}
/* 编辑器分类下拉多选 */
.cat-drop-select{position:relative}
.cat-drop-select #catDropBtn{width:100%;text-align:left;padding:8px 10px;border:1px solid #e0e6ed;border-radius:10px;background:var(--card);color:var(--t1);font-size:.82rem;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cat-drop-panel{display:none;position:absolute;left:0;top:calc(100%+4px);width:min(260px,100%);background:var(--card);border:1px solid #e0e6ed;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:128px;overflow:auto;z-index:60;padding:4px}
.cat-drop-panel.show{display:block}
.cat-drop-panel label{display:flex;align-items:center;gap:8px;min-height:30px;padding:0 10px;font-size:.82rem;cursor:pointer;border-radius:8px;box-sizing:border-box;margin:0}
.cat-drop-panel .cat-opt-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cat-drop-panel input[type=checkbox]{flex:none;margin:0;width:15px;height:15px;accent-color:var(--b)}
.cat-drop-panel label:hover{background:rgba(0,0,0,.05)}
.dark .cat-drop-panel label:hover{background:rgba(255,255,255,.08)}
</style>
  <?php if(isAdmin()):?>
  <link rel="stylesheet" href="editormd/css/editormd.min.css" />
  <link rel="stylesheet" href="editormd/css/font-awesome.min.css" />
  <script src="editormd/lib/jquery-1.12.4.min.js"></script>
  <?php endif;?>
  <?php if($singlePost||isAdmin()):?>
  <link rel="stylesheet" href="editormd/lib/highlight-github.min.css">
  <script src="editormd/lib/highlight.min.js"></script>
  <?php endif;?>
  <?php if($singlePost&&(strpos($singlePost['content'],'```mermaid')!==false||stripos($singlePost['content'],'language-mermaid')!==false||strpos($singlePost['content'],'class="mermaid"')!==false)):?>
  <script src="editormd/lib/mermaid.min.js"></script>
  <?php endif;?>
  <style>
.single-post .content pre,.single-post .content .hljs{background:#f6f8fa;color:#24292f;border:1px solid rgba(0,0,0,.08)}
.single-post .content .hljs-comment,.single-post .content .hljs-quote,.single-post .content .hljs-meta{color:#6e7781}
.single-post .content .hljs-keyword,.single-post .content .hljs-selector-tag,.single-post .content .hljs-literal,.single-post .content .hljs-section,.single-post .content .hljs-link{color:#cf222e}
.single-post .content .hljs-string,.single-post .content .hljs-regexp,.single-post .content .hljs-addition{color:#0a3069}
.single-post .content .hljs-number,.single-post .content .hljs-symbol,.single-post .content .hljs-bullet{color:#0550ae}
.single-post .content .hljs-title,.single-post .content .hljs-title.function_,.single-post .content .hljs-title.class_{color:#8250df}
.single-post .content .hljs-attr,.single-post .content .hljs-variable,.single-post .content .hljs-template-variable,.single-post .content .hljs-name{color:#0550ae}
.single-post .content .hljs-built_in,.single-post .content .hljs-builtin-name,.single-post .content .hljs-type,.single-post .content .hljs-attribute{color:#953800}
.single-post .content .hljs-deletion{color:#82071e;background:rgba(255,129,130,.2)}
.single-post .content .hljs-addition{color:#116329;background:rgba(26,127,55,.15)}
.dark .single-post .content pre,.dark .single-post .content .hljs{background:#1f242c;color:#e6edf3;border-color:var(--border)}
.dark .single-post .content .hljs-comment,.dark .single-post .content .hljs-quote,.dark .single-post .content .hljs-meta{color:#8b949e}
.dark .single-post .content .hljs-keyword,.dark .single-post .content .hljs-selector-tag,.dark .single-post .content .hljs-literal,.dark .single-post .content .hljs-section,.dark .single-post .content .hljs-link{color:#ff7b72}
.dark .single-post .content .hljs-string,.dark .single-post .content .hljs-regexp,.dark .single-post .content .hljs-addition{color:#a5d6ff}
.dark .single-post .content .hljs-number,.dark .single-post .content .hljs-symbol,.dark .single-post .content .hljs-bullet{color:#79c0ff}
.dark .single-post .content .hljs-title,.dark .single-post .content .hljs-title.function_,.dark .single-post .content .hljs-title.class_{color:#d2a8ff}
.dark .single-post .content .hljs-attr,.dark .single-post .content .hljs-variable,.dark .single-post .content .hljs-template-variable,.dark .single-post .content .hljs-name{color:#79c0ff}
.dark .single-post .content .hljs-built_in,.dark .single-post .content .hljs-builtin-name,.dark .single-post .content .hljs-type,.dark .single-post .content .hljs-attribute{color:#ffa657}
.dark .single-post .content .hljs-deletion{color:#ffa198;background:rgba(255,45,85,.15)}
.dark .single-post .content .hljs-addition{color:#7ee787;background:rgba(46,160,67,.15)}
  </style>
</head><body>
<div id="readingBar"></div>
<header><div class="header-inner">
<a href="?" class="logo" style="font-family:<?=htmlspecialchars(siteFontCss($db),ENT_QUOTES)?>"><?=htmlspecialchars($sn)?><svg class="logo-badge" width="38" height="38" viewBox="0 0 32 32" aria-hidden="true"><defs><linearGradient id="logoGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--g1)"/><stop offset="1" stop-color="var(--g2)"/></linearGradient></defs><rect x="1" y="1" width="30" height="30" rx="9" fill="url(#logoGrad)"/><path d="M9 5h14a2 2 0 0 1 2 2v20l-9-5-9 5V7a2 2 0 0 1 2-2z" fill="#fff"/><path d="M13 12h6M13 16h6M13 20h4" stroke="var(--g1)" stroke-width="1.4" stroke-linecap="round" fill="none"/></svg></a>
<nav>
  <a href="?" class="<?=!$slug&&!$isAdminPage&&!isset($_GET['archive'])&&!isset($_GET['action'])?'active':''?>"><?=ico('home',16)?> 首页</a>
  <a href="?archive=1" class="<?=isset($_GET['archive'])?'active':''?>"><?=ico('calendar',16)?> 归档</a>
  <a href="?action=rss" class="<?=($_GET['action']??'')==='rss'?'active':''?>"><?=ico('rss',16)?> RSS</a>
  <?php if(isAdmin()):?>
  <select id="themeSel" class="theme-select" onchange="setTheme(this.value)" title="选择主题">
    <option value="blue">💙 蓝色经典</option>
    <option value="warm">🌸 暖色</option>
    <option value="light">☀️ 亮色</option>
    <option value="dark">🌙 深色</option>
  </select>
  <?php endif;?>
  <?php if(isAdmin()):?><a href="?admin=posts" class="nav-btn <?=$isAdminPage?'active':''?>"><?=gearIcon(15,'currentColor')?> 管理</a>
<?php else:?><a href="?admin=login" class="nav-btn"><?=ico('key',15)?> 登录</a><?php endif;?>
</nav>
</div></header>
<main class="container" id="app">

<?php if($isAdminPage && isAdmin()):?>
  <div class="admin-wrap">
  <div class="admin-header">
<h1><?=gearIcon(24,'var(--b)')?>管理面板</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="?admin=posts" class="btn tab-btn <?=$page==='posts'?'active':''?>"><?=ico('file-text',15)?> 文章</a>
      <a href="?admin=trash" class="btn tab-btn <?=$page==='trash'?'active':''?>"><?=ico('trash',15)?> 回收站</a>
      <a href="?admin=stats" class="btn tab-btn <?=$page==='stats'?'active':''?>"><?=ico('chart',15)?> 统计</a>
      <a href="?admin=cat" class="btn tab-btn <?=$page==='cat'?'active':''?>"><?=ico('tag',15)?> 分类</a>
      <a href="?admin=comment" class="btn tab-btn <?=$page==='comment'?'active':''?>"><?=ico('message',15)?> 评论</a>
      <a href="?admin=files" class="btn tab-btn <?=$page==='files'?'active':''?>"><?=ico('folder',15)?> 文件</a>
<a href="?admin=settings" class="btn tab-btn <?=$page==='settings'?'active':''?>"><?=gearIcon(15,'currentColor')?> 系统设置</a>
      <a href="?action=logout" class="btn btn-danger" onclick="return confirm('确定退出？')"><?=ico('logout',15)?> 退出</a>
      <button onclick="cleanupUploads()" class="btn btn-secondary"><?=ico('trash',15)?> 清理全部未引用</button>
    </div>
  </div>
  <div id="adminApp"><div class="loading"><span class="spinner"></span>加载中...</div></div>
  </div>
<?php elseif($isAdminPage && !isAdmin()):?>
  <div style="max-width:400px;margin:60px auto;background:var(--card);border-radius:var(--br);padding:32px;box-shadow:var(--s);text-align:center">
<h2 style="margin-bottom:20px"><?=ico('key',18)?> 管理员登录</h2>
    <input type="text" id="loginUser" placeholder="用户名" style="width:100%;padding:12px 16px;border:1px solid #e0e6ed;border-radius:40px;font-size:.95rem;outline:none;margin-bottom:12px;box-sizing:border-box">
    <input type="password" id="loginPass" placeholder="密码" style="width:100%;padding:12px 16px;border:1px solid #e0e6ed;border-radius:40px;font-size:.95rem;outline:none;margin-bottom:16px;box-sizing:border-box" onkeydown="if(event.key==='Enter')doLogin()">
    <button id="loginBtn" onclick="doLogin()" style="width:100%;padding:12px;background:var(--b);color:#fff;border:none;border-radius:40px;font-size:1rem;font-weight:600;cursor:pointer">登 录</button>
    <div id="loginErr" style="color:#ef4444;font-size:.85rem;margin-top:8px;display:none"></div>
  </div>
<?php elseif($slug&&$singlePost&&!empty($singlePost['locked'])):?>
  <div style="max-width:420px;margin:60px auto;background:var(--card);border-radius:var(--br);padding:32px;box-shadow:var(--s);text-align:center">
<h2 style="margin-bottom:10px"><?=ico('lock',18)?> 私密文章</h2>
    <p style="color:var(--t3);font-size:.85rem;margin-bottom:18px">这篇文章需要密码才能查看</p>
    <form method="post" action="?action=post_pw">
      <input type="hidden" name="slug" value="<?=htmlspecialchars($slug,ENT_QUOTES)?>">
      <input type="password" name="pwd" placeholder="访问密码" autocomplete="new-password" required style="width:100%;padding:12px 16px;border:1px solid #e0e6ed;border-radius:40px;font-size:.95rem;outline:none;margin-bottom:12px;box-sizing:border-box">
      <button type="submit" style="width:100%;padding:12px;background:var(--b);color:#fff;border:none;border-radius:40px;font-size:1rem;font-weight:600;cursor:pointer">查看内容</button>
    </form>
    <?php if(($_GET['pw']??'')==='error'):?><div style="color:#ef4444;font-size:.85rem;margin-top:10px">密码不正确</div><?php endif;?>
    <?php if(($_GET['debug']??'')==='1'):$dp=$db->prepare("SELECT password FROM posts WHERE id=?");$dp->execute([(int)$singlePost['id']]);$dpw=$dp->fetchColumn()?:'';?>
    <div style="margin-top:10px;font-size:.75rem;color:var(--t3);text-align:left">密码存储：<?=strpos($dpw,'$2')===0?'bcrypt 哈希':'非 bcrypt（可能是明文）'?> · 已解锁：<?=!empty($_SESSION['post_pw'][(int)$singlePost['id']])?'是':'否'?> · 提交到达：<?=isset($_GET['pwd'])||isset($_GET['pw'])?'是':'否'?> · 提交长度：<?=isset($_GET['pwd'])?mb_strlen($_GET['pwd'],'UTF-8'):'-'?></div>
    <?php endif;?>
  </div>
<?php elseif($slug&&$singlePost):?>
  <?php includePost($singlePost,$cats,$db);?>
<?php elseif(isset($_GET['archive'])):?>
  <?php includeArchive($cats);?>
<?php else:?>
  <?php includeHome($cats);?>
<?php endif;?>
</main>
<footer><div class="container">
  <?php
  $totalVisitors=(int)$db->query("SELECT COALESCE(SUM(uv),0) FROM stats_daily")->fetchColumn();
  $tvStmt=$db->prepare("SELECT uv FROM stats_daily WHERE date=?");$tvStmt->execute([date('Y-m-d')]);
  $todayVisitors=(int)$tvStmt->fetchColumn();
  ?>
  <div style="margin-bottom:8px;display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
    <span><?=ico('user',14)?>访问人数 <?=number_format($totalVisitors)?></span>
    <span><?=ico('clock',14)?>今日 <?=number_format($todayVisitors)?></span>
  </div>
  <?php
  $scFooter=setting($db,'site_copyright');
  echo $scFooter ?: 'Powered by <strong><a href="https://github.com/amw1933/miniblog-single" target="_blank" rel="noopener" style="color:var(--b)">MiniBlog</a></strong> &copy; '.date('Y').' · <a href="https://github.com/amw1933/miniblog-single" target="_blank" rel="noopener" style="color:var(--b)">GitHub</a>';
  ?>
</div></footer>
<script>
// CSRF 令牌
window.CSRF = '<?=csrf_token()?>';
// API 请求包装
function apiFetch(url, options){
  options = options || {};
  options.headers = Object.assign({'X-CSRF-Token': window.CSRF}, options.headers || {});
  return fetch(url, options);
}
// 暗色模式切换
function setTheme(t){
  var el=document.documentElement;
  el.classList.remove('dark','warm','blue');
  if(t==='dark')el.classList.add('dark');
  else if(t==='warm')el.classList.add('warm');
  else if(t==='blue')el.classList.add('blue');
  var sel=document.getElementById('themeSel');if(sel)sel.value=t;
  var fv=document.querySelector('link[rel="icon"]');if(fv)fv.href='?action=favicon&theme='+t;
  var av=document.querySelector('link[rel="apple-touch-icon"]');if(av)av.href='?action=favicon&png=1&theme='+t;
  if(sel&&window.CSRF)apiFetch('?action=admin_set_theme&theme='+encodeURIComponent(t),{method:'POST'}).then(function(r){return r.json()}).then(function(d){if(!d||!d.ok)alert('主题保存失败，请重试')}).catch(function(){alert('主题保存失败，请重试')});
}
function syncThemeSelect(){
  var sel=document.getElementById('themeSel');if(!sel)return;
  sel.value=t||'blue';
}
function escapeHtml(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML}
function showModal(html,cb){
  var m=document.getElementById('modal');
  if(!m){var d=document.createElement('div');d.id='modal';d.className='modal';d.innerHTML='<div class="modal-content" id="modalContent"></div>';document.body.appendChild(d);m=d}
  m.onclick=null;m.ondragover=null;m.onkeydown=null;
  document.getElementById('modalContent').innerHTML=html;m.classList.add('show');
  var mc=document.getElementById('modalContent');
  if(mc&&!mc._stop){mc.onclick=function(e){e.stopPropagation()};mc._stop=true;}
  window._editingPostId=window._editingPostId||0;if(cb)setTimeout(cb,50);
}
function hideModal(){
  var m=document.getElementById('modal');
  if(m)m.classList.remove('show');
  if(window._draftTimer){clearInterval(window._draftTimer);window._draftTimer=null;}
  if(window._editor){ try{window._editor.destroy()}catch(e){} window._editor=null; }
}

// 登录函数（修复后）
function doLogin(){
  var u=document.getElementById('loginUser').value.trim();
  var p=document.getElementById('loginPass').value;
  var errEl=document.getElementById('loginErr');
  var btn=document.getElementById('loginBtn');
  if(!u||!p){
    errEl.textContent='请填写完整';
    errEl.style.display='block';
    return;
  }
  if(btn) btn.disabled=true, btn.textContent='登录中...';
  fetch('?action=login',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({user:u,pass:p})
  }).then(function(res){
    if(!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  }).then(function(d){
    if(d.ok){
      location.href = '?admin=posts';
    } else {
      errEl.textContent=d.error||'登录失败';
      errEl.style.display='block';
      if(btn) btn.disabled=false, btn.textContent='登 录';
    }
  }).catch(function(err){
    errEl.textContent='登录请求失败，请重试';
    errEl.style.display='block';
    if(btn) btn.disabled=false, btn.textContent='登 录';
    console.error(err);
  });
}

// ─── 管理面板 JS ──────────────────────────────────────
function loadAdminPage(p){
  var valid = ['posts','trash','stats','cat','comment','files','settings'];
  if(valid.indexOf(p) === -1) p = 'posts';
  if(p==='posts')loadPostsList();
  else if(p==='trash')loadTrash();
  else if(p==='stats')loadDashboard();
  else if(p==='cat')loadCatsList();
  else if(p==='comment')loadCommentsList();
  else if(p==='files')loadFiles();
  else if(p==='settings')loadSettings();
}
function loadDashboard(){
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  apiFetch('?action=admin_stats').then(function(r){return r.json()}).then(function(d){
    if(d.error){app.innerHTML='<div class="empty">'+d.error+'</div>';return;}
    var h='<div class="stats-grid">'+
      '<div class="stat-card"><b>'+d.posts+'</b><span>文章</span></div>'+
      '<div class="stat-card"><b>'+d.published+'</b><span>已发布</span></div>'+
      '<div class="stat-card"><b>'+d.comments+'</b><span>评论</span></div>'+
      '<div class="stat-card"><b>'+d.pending+'</b><span>待审核</span></div>'+
      '<div class="stat-card"><b>'+d.views+'</b><span>总浏览</span></div>'+
      '<div class="stat-card"><b>'+d.visitors+'</b><span>访问人数</span></div>'+
      '<div class="stat-card"><b>'+d.today_visitors+'</b><span>今日访问</span></div>'+
    '</div>';
    h+='<div style="background:var(--card);border-radius:var(--br);padding:20px;box-shadow:var(--s);margin-top:16px"><h3 style="font-size:1rem;margin-bottom:12px">近14天访问趋势</h3>';
    (d.daily||[]).forEach(function(r){var max=Math.max(1,r.pv,r.uv);h+='<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:.78rem"><span style="width:78px;flex:none;color:var(--t3)">'+r.date.slice(5)+'</span><div style="flex:1;height:14px;background:#eef2ff;border-radius:7px;overflow:hidden"><div style="width:'+Math.round(r.pv/max*100)+'%;height:100%;background:var(--b);border-radius:7px"></div></div><span style="width:62px;flex:none">PV '+r.pv+'</span><span style="width:62px;flex:none">UV '+r.uv+'</span></div>';});
    h+='</div>';
    h+='<div style="background:var(--card);border-radius:var(--br);padding:20px;box-shadow:var(--s);margin-top:16px"><h3 style="font-size:1rem;margin-bottom:12px">热门文章 Top 10</h3>';
    (d.top||[]).forEach(function(p,i){h+='<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid rgba(0,0,0,.04);font-size:.85rem"><a href="?slug='+encodeURIComponent(p.slug)+'" target="_blank" style="color:var(--t1);text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(i+1)+'. '+escapeHtml(p.title)+'</a><span style="color:var(--t3);flex:none">'+p.views+' 次</span></div>';});
    h+='</div>';
    h+='<div style="background:var(--card);border-radius:var(--br);padding:20px;box-shadow:var(--s);margin-top:16px"><h3 style="font-size:1rem;margin-bottom:12px">最近访问 IP（近90天）</h3>';
    h+='<table class="admin-table"><thead><tr><th>IP</th><th>访问次数</th><th>最后访问</th><th>UA</th></tr></thead><tbody>';
    (d.recent_ips||[]).forEach(function(r){
      h+='<tr><td style="font-family:monospace;font-size:.8rem">'+escapeHtml(r.ip)+'</td><td>'+r.visits+'</td><td style="font-size:.82rem">'+escapeHtml(r.last_at)+'</td><td style="font-size:.75rem;color:var(--t3);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+escapeHtml(r.ua||'')+'">'+escapeHtml(r.ua||'-')+'</td></tr>';
    });
    if(!(d.recent_ips||[]).length)h+='<tr><td colspan="4" style="text-align:center;color:var(--t3);padding:20px">暂无访问记录</td></tr>';
    h+='</tbody></table></div>';
    app.innerHTML=h;
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function loadPostsList(page){
  page=page||1;
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  apiFetch('?action=admin_posts&page='+page).then(function(r){return r.json()}).then(function(d){
    if(!d.posts||!d.posts.length){
app.innerHTML='<div class="empty"><div class="empty-icon"><?=ico('file-text',30)?></div><p>暂无文章</p><button onclick="openEditor()" class="btn btn-primary" style="margin-top:12px">写第一篇</button></div>';return;
    }
    var html='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><span style="color:var(--t2);font-size:.85rem">共 '+d.total+' 篇文章</span><button onclick="openEditor()" class="btn btn-primary"><?=ico('edit',14)?> 写文章</button></div>';
    html+='<div class="batch-bar"><input type="checkbox" id="selAll" onchange="toggleAll(this)"><span>全选</span><button onclick="batchOp(\'publish\')">批量发布</button><button onclick="batchOp(\'draft\')">转草稿</button><button onclick="batchOp(\'delete\')">批量删除</button><button onclick="batchOp(\'category\')">改分类</button><button onclick="batchOp(\'tag\')">加标签</button><button onclick="batchOp(\'remove_tag\')">去标签</button></div>';
    html+='<table class="admin-table"><thead><tr><th style="width:36px"><input type="checkbox" id="selAll2" onchange="toggleAll(this)"></th><th>标题</th><th>分类</th><th>状态</th><th>日期</th><th>操作</th></tr></thead><tbody>';
    d.posts.forEach(function(p){
      var titleLink = (p.pinned?'<span style="background:#f59e0b;color:#fff;font-size:.68rem;padding:2px 8px;border-radius:20px;margin-right:6px">置顶</span>':'') + '<a href="?slug=' + encodeURIComponent(p.slug) + '" target="_blank" style="color:var(--b); font-weight:600;">' + escapeHtml(p.title) + '</a>';
      var tagHtml=((p.tags||[]).map(function(t){return '<a class="tag-chip" href="?tag='+encodeURIComponent(t.slug)+'">'+escapeHtml(t.name)+'</a>'})).join('');
      var statusHtml=p.is_scheduled?'<span class="status-badge draft">定时</span>':'<span class="status-badge '+(p.published?'published':'draft')+'">'+(p.published?'已发布':'草稿')+'</span>';
      html+='<tr><td><input type="checkbox" class="rowSel" value="'+p.id+'"></td><td style="font-weight:600">' + titleLink + (tagHtml?'<div class="card-tags" style="margin-top:4px">'+tagHtml+'</div>':'') + '</td><td style="color:var(--t2)">'+escapeHtml(p.cat_name||'未分类')+'</td><td>'+statusHtml+'</td><td style="font-size:.82rem;color:var(--t2)">'+p.created_at.split(' ')[0]+'</td><td><div class="actions">'+
        '<button onclick="window.open(\'?slug='+encodeURIComponent(p.slug)+'\')" style="background:#eef2ff;color:var(--b)">查看</button>'+
        '<button onclick="openEditor('+p.id+')" style="background:#eef2ff;color:var(--b)">编辑</button>'+
        (p.pinned?'<button onclick="setPostPin('+p.id+',0)" style="background:#fef3c7;color:#b45309">取消置顶</button>':'<button onclick="setPostPin('+p.id+',1)" style="background:#fefce8;color:#a16207">置顶</button>')+
        '<button onclick="deletePost('+p.id+')" style="background:#fef2f2;color:#ef4444">删除</button></div></td></tr>';
    });
    html+='</tbody></table>';
    if(d.pages>1){
      html+='<div class="pagination" style="padding:16px 0">';
      for(var i=1;i<=d.pages;i++)html+='<button class="'+(i===d.page?'active':'')+'" onclick="loadPostsList('+i+')">'+i+'</button>';
      html+='</div>';
    }
    app.innerHTML=html;
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function setPostPin(id,pin){
  apiFetch('?action=admin_set_pin&id='+id+'&pin='+(pin?1:0)).then(function(r){return r.json()}).then(function(d){if(d.ok)loadPostsList();else alert(d.error||'操作失败')}).catch(function(){alert('请求失败，请重试')});
}
function selectedIds(){var ids=[];document.querySelectorAll('.rowSel:checked').forEach(function(c){ids.push(parseInt(c.value,10));});return ids;}
function toggleAll(cb){document.querySelectorAll('.rowSel').forEach(function(c){c.checked=cb.checked;});}
function batchOp(op){
  var ids=selectedIds();if(!ids.length)return alert('请先勾选文章');
  var value='';
  if(op==='category'){value=prompt('输入目标分类 ID（分类页面第一列数字）');if(value===null)return;}
  if(op==='tag'){value=prompt('输入要添加的标签');if(value===null)return;}
  if(op==='remove_tag'){value=prompt('输入要移除的标签');if(value===null)return;}
  apiFetch('?action=admin_batch',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({op:op,ids:ids,value:value})}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('已处理 '+d.count+' 篇');loadPostsList();}else alert(d.error||'操作失败')}).catch(function(){alert('请求失败，请重试')});
}
function loadTrash(){
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  apiFetch('?action=admin_trash').then(function(r){return r.json()}).then(function(d){
if(!d.posts||!d.posts.length){app.innerHTML='<div class="empty"><div class="empty-icon"><?=ico('trash',30)?></div><p>回收站是空的</p></div>';return;}
    var html='<div style="margin-bottom:12px;color:var(--t2);font-size:.85rem">共 '+d.posts.length+' 篇已删除文章</div><table class="admin-table"><thead><tr><th>标题</th><th>删除时间</th><th>操作</th></tr></thead><tbody>';
    d.posts.forEach(function(p){
    html+='<tr><td style="font-weight:600">'+escapeHtml(p.title)+'</td><td style="font-size:.82rem;color:var(--t2)">'+p.deleted_at+'</td><td><div class="actions"><button onclick="openEditor('+p.id+',1)" style="background:#f1f4f9;color:var(--t1)">修改</button><button onclick="restorePost('+p.id+')" style="background:#eef2ff;color:var(--b)">恢复</button><button onclick="purgePost('+p.id+')" style="background:#fef2f2;color:#ef4444">永久删除</button></div></td></tr>';
    });
    html+='</tbody></table>';app.innerHTML=html;
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function restorePost(id){apiFetch('?action=admin_restore_post&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadTrash();else alert(d.error||'恢复失败')}).catch(function(){alert('请求失败，请重试')});}
function purgePost(id){if(!confirm('永久删除后不可恢复，确定？'))return;apiFetch('?action=admin_purge_post&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadTrash();else alert(d.error||'删除失败')}).catch(function(){alert('请求失败，请重试')});}
function editorUpload(type){
  var input=document.getElementById('uploadInput');
  if(!input){input=document.createElement('input');input.type='file';input.id='uploadInput';input.style.display='none';document.body.appendChild(input)}
  input.accept=type==='image'?'image/*':'*/*';
  input.onchange=function(){
    var file=input.files[0];if(!file)return;
    var overlay=document.createElement('div');
    overlay.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:9999';
    overlay.innerHTML='<div style="background:#fff;border-radius:12px;padding:24px 32px;min-width:320px;box-shadow:0 8px 32px rgba(0,0,0,.2)">'+
      '<div style="font-size:.9rem;font-weight:600;margin-bottom:12px;color:#1a2332;display:flex;align-items:center;gap:8px">'+
'<span style="font-size:1.2rem">'+(type==='image'?'<?=ico('image',18)?>':'<?=ico('paperclip',18)?>')+'</span><span id="upName">'+file.name+'</span></div>'+
      '<div style="background:#e2e8f0;border-radius:40px;height:8px;overflow:hidden;margin-bottom:8px">'+
      '<div id="upBar" style="background:linear-gradient(90deg,#2563eb,#7c3aed);height:100%;border-radius:40px;width:0%;transition:width .2s"></div></div>'+
      '<div style="display:flex;justify-content:space-between;font-size:.78rem;color:#64748b">'+
      '<span id="upPct">0%</span><span id="upSize"></span></div></div>';
    document.body.appendChild(overlay);
    var upBar=overlay.querySelector('#upBar'),upPct=overlay.querySelector('#upPct'),upSize=overlay.querySelector('#upSize');
    function fmtSize(b){if(b<1024)return b+' B';if(b<1048576)return(b/1024).toFixed(1)+' KB';return(b/1048576).toFixed(1)+' MB'}
    var fd=new FormData();fd.append('file',file);
    var xhr=new XMLHttpRequest();
    xhr.upload.onprogress=function(e){
      if(e.lengthComputable){
        var pct=Math.round(e.loaded/e.total*100);
        upBar.style.width=pct+'%';upPct.textContent=pct+'%';
        upSize.textContent=fmtSize(e.loaded)+' / '+fmtSize(e.total);
      }
    };
    xhr.onload=function(){
      document.body.removeChild(overlay);
      try{var d=JSON.parse(xhr.responseText)}catch(e){alert('上传失败');return}
      if(d.url){
        var md=type==='image'?'!['+file.name+']('+d.url+')':'['+file.name+']('+d.url+')';
        if(window._editor&&window._editor.cm){ window._editor.cm.replaceSelection(md); }
        else{
          var ta=document.getElementById('postContent');
          if(ta){var v=ta.value,s=ta.selectionStart||v.length;ta.value=v.substring(0,s)+md+v.substring(ta.selectionEnd||s);}
        }
      }else alert(d.error||'上传失败');
    };
    xhr.onerror=function(){document.body.removeChild(overlay);alert('上传失败')};
    xhr.open('POST','?action=admin_upload&type='+type);
    xhr.setRequestHeader('X-CSRF-Token', window.CSRF);
    xhr.send(fd);
    input.value='';
  };
  input.click();
}
/** 插入视频（YouTube 或直接视频 URL） */
function insertVideo(){
  var url=prompt('请输入视频链接：\n支持 YouTube 链接或直接视频 URL（.mp4/.webm 等）');
  if(!url||!url.trim())return;
  url=url.trim();
  var cm=window._editor&&window._editor.cm;
  if(!cm)return;
  // 自动识别 YouTube 链接
  var ytMatch=url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
  if(ytMatch){
    cm.replaceSelection('!video[YouTube]('+url+')');
  }else{
    cm.replaceSelection('!video[视频]('+url+')');
  }
}
function addTableToEditor(){
  var cm=window._editor&&window._editor.cm;if(!cm)return;
  var input=prompt('插入表格，输入：列数,行数（例如 3,5）','3,3');
  if(input===null)return;
  var parts=input.split(/[,，\s]+/);
  var cols=parseInt(parts[0],10)||3, rows=parseInt(parts[1],10)||2, c, r;
  cols=Math.min(Math.max(cols,1),20);rows=Math.min(Math.max(rows,1),50);
  var headCells=[];for(c=0;c<cols;c++)headCells.push('列'+(c+1));
  var lines=['| '+headCells.join(' | ')+' |'];
  var seps=[];for(c=0;c<cols;c++)seps.push('---');
  lines.push('| '+seps.join(' | ')+' |');
  for(r=0;r<rows;r++){var cells=[];for(c=0;c<cols;c++)cells.push(' ');lines.push('| '+cells.join(' | ')+' |');}
  cm.replaceSelection(lines.join('\n')+'\n');cm.focus();
  setTimeout(renderPreviewStyles,50);
}
function bindPasteHandler(){
  if(!window._editor)return;
  var cm=window._editor.cm;
  if(!cm)return;
  var wrapper=cm.getWrapperElement();
  if(wrapper && !wrapper._pasteBound){
    wrapper.addEventListener("paste", function(e){handleEditorPaste(e)}, true);
    wrapper._pasteBound = true;
  }
}
function handleEditorPaste(e){
  var cd=e.clipboardData||window.clipboardData;
  if(!cd)return;
  var html=cd.getData("text/html");
  var text=cd.getData("text/plain");

  // ===== 代码类粘贴检测 =====
  // 1. 如果 HTML 含 <img>，优先走富文本处理（需要下载图片），不进入代码块检测
  var hasImg = html && /<img[^>]+src\s*=/i.test(html);
  // 2. HTML 含 <pre> 且无 <img> → 从 IDE/网站复制代码，直接提取纯文本包裹代码块
  if(!hasImg && html && html.indexOf('<pre')>=0){
    e.preventDefault();e.stopPropagation();
    var cm=window._editor&&window._editor.cm;
    if(!cm)return;
    var codeText = text || html.replace(/<[^>]+>/g, '').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
    var lang=detectCodeLang(codeText);
    cm.replaceSelection('```'+lang+'\n'+codeText+'\n```');
    return;
  }
  // 3. 纯文本看起来像代码（无 HTML 富文本干扰时）
  if(!hasImg && text && !(html&&html.length>=10&&/<(h[1-6]|table|ul|ol|blockquote|strong|em|a)[^>]*>/i.test(html))){
    // 检测 YouTube 链接粘贴
    if(text.trim().match(/^(https?:\/\/)?(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)[a-zA-Z0-9_-]{11}/)){
      e.preventDefault();e.stopPropagation();
      var cm=window._editor&&window._editor.cm;
      if(cm)cm.replaceSelection('!video[YouTube]('+text.trim()+')');
      return;
    }
    if(looksLikeCode(text)){
      e.preventDefault();e.stopPropagation();
      var cm=window._editor&&window._editor.cm;
      if(!cm)return;
      var lang=detectCodeLang(text);
      cm.replaceSelection('```'+lang+'\n'+text+'\n```');
      return;
    }
    return; // 普通文本，走默认粘贴
  }

  // 富文本粘贴（含图片/表格等），走 API 处理
  if(!html||html.length<10)return;
  if(!hasImg && !/<(h[1-6]|table|ul|ol|blockquote|strong|em|a)[^>]*>/i.test(html))return;
  e.preventDefault();e.stopPropagation();
  var loading=document.createElement('div');
  loading.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.75);color:#fff;padding:12px 24px;border-radius:8px;font-size:.9rem;z-index:9999';
  loading.textContent='正在处理粘贴内容，下载图片中...';
  document.body.appendChild(loading);
  apiFetch('?action=process_paste',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({html:html,base_url:location.href})})
  .then(function(r){return r.json()})
  .then(function(d){
    document.body.removeChild(loading);
    var cm=window._editor&&window._editor.cm;
    if(!cm)return;
    if(d.markdown)cm.replaceSelection(d.markdown);
    else if(d.html)cm.replaceSelection(d.html);
    else cm.replaceSelection(cd.getData("text/plain")||'');
  })
  .catch(function(){
    document.body.removeChild(loading);
    var cm=window._editor&&window._editor.cm;
    if(cm)cm.replaceSelection(cd.getData("text/plain")||'');
  });
}

/**
 * 判断文本是否像代码
 */
function looksLikeCode(t){
  if(!t)return false;
  var lines=t.split('\n').filter(function(l){return l.trim()!==''});
  if(lines.length<2)return false; // 单行不算代码块
  // 含缩进的行数
  var indented=0;
  for(var i=0;i<lines.length;i++){
    if(/^[\t ]/.test(lines[i]))indented++;
  }
  // 半数是缩进行，或包含明显代码特征
  var codePatterns=/[{}=;\[\]()<>]|=>|->|\$[a-zA-Z_]|function|class|def |import |from |require|const |let |var |\x3C?php|#include|int |float |void |return |public |private |static |async |await |export |module\.|<\/[a-z]+>/;
  var codeLineCount=0;
  for(var i=0;i<lines.length;i++){
    if(codePatterns.test(lines[i]))codeLineCount++;
  }
  return (indented>=lines.length*0.3) || (codeLineCount>=lines.length*0.2);
}

/**
 * 检测代码语言（简单启发式）
 */
function detectCodeLang(t){
  if(/^\x3C?php|\x3Cscript.*?\/?>|\x3C!DOCTYPE|\x3Chtml/i.test(t)) return t.indexOf('\x3C?php')>=0?'php':'html';
  if(/^import\s+.*?\s+from\s|^const\s+\w+\s*=\s*require|module\.exports|export\s+(default|const|function)/im.test(t)) return 'javascript';
  if(/^#include\s*[<"]/im.test(t)) return 'c';
  if(/^#include\s*<iostream>/im.test(t)||/int\s+main\s*\(/m.test(t)) return 'cpp';
  if(/^def\s+\w+\s*\(/im.test(t)) return 'python';
  if(/^function\s+\w+\s*\(/im.test(t)||/^const\s+\w+\s*=\s*\(?[^)]*\)?\s*=>/m.test(t)) return 'javascript';
  if(/^class\s+\w+[\s\S]*?\{[\s\S]*?\}/m.test(t)) return t.indexOf('extends')>=0||t.indexOf('implements')>=0?'java':'javascript';
  if(/^SELECT\s|^INSERT\s|^UPDATE\s|^DELETE\s|^CREATE\s/i.test(t.trim())) return 'sql';
  if(/^\[?[\w.-]+\s*=\s*[^=]/m.test(t)&&!/^[\w\u4e00-\u9fff]/.test(t)) return 'ini';
  if(/^@\w+\s*\(/m.test(t)||/^#\[/m.test(t)) return 'java';
  if(/^-\s+\[/m.test(t)) return 'markdown';
  return '';
}
function slugifyTitle(t){
  return (t||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'')||'post';
}
function bindSlugFollow(origTitle,origSlug){
  var tb=document.getElementById('postTitle');var sb=document.getElementById('postSlug');
  if(!tb||!sb)return;
  window._slugManual=false;
  if(origSlug){sb.value=origSlug;if(origSlug!==slugifyTitle(origTitle))window._slugManual=true;}
  else{sb.value='';}
  sb.oninput=function(){window._slugManual=true;};
  tb.oninput=function(){if(!window._slugManual){sb.value=slugifyTitle(tb.value);}};
}
function updateMainCat(){
  var cb=document.querySelector('.postCatCb:checked');
  var h=document.getElementById('postCat');if(h)h.value=cb?cb.value:'';
  var b=document.getElementById('catDropBtn');
  if(b){
    var names=[];document.querySelectorAll('.postCatCb:checked').forEach(function(c){names.push(c.parentElement.textContent.trim());});
    b.textContent=names.length?('已选 '+names.length+' 个：'+names.join('、')):'点击选择分类 ▾';
    b.style.color=names.length?'var(--b)':'';
  }
}
function toggleCatDrop(e){
  if(e){e.stopPropagation();}
  var p=document.getElementById('catDropPanel');if(p)p.classList.toggle('show');
}
document.addEventListener('click',function(e){
  var box=document.querySelector('.cat-drop-select');
  var p=document.getElementById('catDropPanel');
  if(p&&box&&!box.contains(e.target))p.classList.remove('show');
});
function setPostCats(ids){
  var list=(ids||[]).map(function(x){return parseInt(x,10)});
  document.querySelectorAll('.postCatCb').forEach(function(cb){cb.checked=list.indexOf(parseInt(cb.value,10))>=0;});
  updateMainCat();
}
function openEditor(id,fromTrash){
  id=id||0;fromTrash=fromTrash?1:0;
  ensureMermaid();
  apiFetch('?action=admin_cats').then(function(r){return r.json()}).then(function(cats){
    var catOpts='';
    function addCatOpts(pid,prefix){cats.forEach(function(c){if((c.parent_id||0)==pid){catOpts+='<label><input type="checkbox" class="postCatCb" value="'+c.id+'" onchange="updateMainCat()"><span class="cat-opt-name">'+prefix+escapeHtml(c.name)+'</span></label>';addCatOpts(c.id,prefix+'　');}});}
    addCatOpts(0,'');
  var html='<h2>'+(id?'编辑文章':'写新文章')+'</h2>'+(fromTrash?'<div style="background:#fff7ed;color:#b45309;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px;font-size:.78rem;margin:0 0 10px"><?=ico('alert',14)?> 此文章在回收站中：下方三个按钮里，只有“保存修改/修改后发布”才会保存并自动恢复文章；点“取消”或直接关闭编辑器都不会保存，删除状态保持不变。</div>':'')+'<label>标题</label><input type="text" id="postTitle" placeholder="文章标题"><div class="form-row"><div style="flex:0 1 220px;min-width:170px"><label>分类（可多选）</label><div class="cat-drop-select"><button type="button" id="catDropBtn" onclick="toggleCatDrop(event)">点击选择分类 ▾</button><div class="cat-drop-panel" id="catDropPanel">'+catOpts+'</div></div><input type="hidden" id="postCat" value=""></div><div><label>状态</label><select id="postStatus"><option value="1">已发布</option><option value="0">草稿</option></select></div><div><label>发表时间</label><input type="date" id="postTime"></div></div><div class="form-row"><div><label>定时发布 <span class="form-hint">可选</span></label><input type="datetime-local" id="postPublish"></div><div><label>标签 <span class="form-hint">逗号分隔</span></label><input type="text" id="postTags" placeholder="PHP, JavaScript"></div><div><label>访问密码 <span class="form-hint">留空公开</span></label><input type="password" id="postPassword" placeholder="留空公开" autocomplete="new-password"><select id="commonPwSelect" onchange="fillCommonPw(this.value)" style="margin-left:8px;padding:6px 10px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.75rem"></select><button type="button" onclick="saveCommonPw()" style="margin-left:4px;padding:6px 12px;border-radius:40px;border:1px solid #e0e6ed;background:#eef2ff;color:var(--b);font-size:.75rem;cursor:pointer">存为常用</button></div></div><label>Slug <span class="form-hint">修改标题后自动跟随，可手动修改</span></label><input type="text" id="postSlug" placeholder="修改标题后自动生成"><div id="draftHint" style="font-size:.75rem;color:var(--t3);margin:4px 0;display:none"></div><label>内容 (支持 Markdown)</label><div class="editor-toolbar" style="display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap">'+
      '<button type="button" onclick="editorUpload(\'image\')" class="btn- btn-upload" style="padding:6px 14px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.82rem;cursor:pointer;font-weight:500">📷 上传图片</button>'+
'<button type="button" onclick="editorUpload(\'file\')" class="btn- btn-upload" style="padding:6px 14px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.82rem;cursor:pointer;font-weight:500"><?=ico('paperclip',13)?> 上传文件</button>'+
'<button type="button" onclick="insertVideo()" class="btn- btn-upload" style="padding:6px 14px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.82rem;cursor:pointer;font-weight:500"><?=ico('video',13)?> 视频</button><label style="margin-left:8px;font-size:.75rem;color:var(--t3);display:inline-flex;align-items:center;gap:4px">字号<select id="editorFontSize" onchange="applyFontToSelection(this.value)" style="padding:6px 10px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.78rem"><option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15" selected>15</option><option value="16">16</option><option value="18">18</option><option value="20">20</option><option value="22">22</option><option value="24">24</option></select></label><label style="margin-left:8px;font-size:.75rem;color:var(--t3);display:inline-flex;align-items:center;gap:4px">颜色<input type="color" id="editorColor" value="#2563eb" onchange="applyColorToSelection(this.value)" oninput="applyColorToSelection(this.value)" style="width:32px;height:26px;padding:0;border:1px solid #e0e6ed;border-radius:8px;background:var(--card);cursor:pointer"></label>'+
      '<label style="margin-left:8px;font-size:.75rem;color:var(--t3);display:inline-flex;align-items:center;gap:4px">标签<select id="editorTagName" style="padding:6px 10px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.78rem"><option value="b">加粗</option><option value="i">斜体</option><option value="u">下划线</option><option value="s">删除线</option><option value="mark">高亮</option><option value="small">小字</option><option value="sub">下标</option><option value="sup">上标</option><option value="code">代码</option><option value="kbd">键盘</option><option value="strong">强调</option><option value="em">着重</option></select></label>'+
'<button type="button" onclick="addTagToSelection()" title="给选中文字加标签" class="btn- btn-upload" style="padding:6px 14px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.82rem;cursor:pointer;font-weight:500"><?=ico('tag',13)?> 加</button>'+
      '<button type="button" onclick="removeTagsFromSelection()" title="去掉选中文字里的标签" class="btn- btn-upload" style="padding:6px 14px;border-radius:40px;border:1px solid #e0e6ed;background:var(--card);color:var(--t1);font-size:.82rem;cursor:pointer;font-weight:500">✂️ 去</button>'+
      '</div><div id="editormd-editor"><textarea id="postContent" style="display:none"></textarea></div><div class="modal-actions"><button onclick="hideModal()" class="btn-secondary" style="padding:8px 24px;border-radius:40px;border:none;font-weight:600;cursor:pointer;background:#eef2f6;color:var(--t1)">取消</button><button onclick="savePost('+id+')" class="btn-secondary" style="padding:8px 24px;border-radius:40px;border:none;font-weight:600;cursor:pointer;background:#eef2f6;color:var(--t1)">'+(id?'保存修改':'存草稿')+'</button><button onclick="savePost('+id+',1)" class="btn-primary" style="padding:8px 24px;border-radius:40px;border:none;font-weight:600;cursor:pointer;background:var(--b);color:#fff">'+(id?'修改后发布':'发布文章')+'</button></div>';
    showModal(html,function(){
      var startEditor=function(){
        setTimeout(function(){
          if(typeof editormd !== 'undefined' && document.getElementById('editormd-editor')){
            try{
              if(window.editormd&&window.editormd.toolbarHandlers)window.editormd.toolbarHandlers.table=function(){addTableToEditor();};
              window._editor = editormd("editormd-editor", {
                width: "100%", height: 520, path: "editormd/lib/",
                codeFold: true, syncScrolling: false, saveHTMLToTextarea: true,
                searchReplace: true, emoji: true, taskList: true, toc: true,
                tex: true, flowChart: true, sequenceDiagram: true,
                onchange: function(){saveDraft(id);setTimeout(renderPreviewStyles,50);},
                imageUpload: true, imageFormats: ["jpg","jpeg","png","gif","webp","bmp"],
                imageUploadURL: "?action=admin_upload&type=image",
                toolbarIcons: function(){ return ["undo","redo","|","bold","italic","strikethrough","|","h1","h2","h3","h4","h5","h6","|","list-ul","list-ol","blockquote","|","code","preformatted-text","code-block","table","|","link","image","hr","|","watch","preview","fullscreen","|","clear-formatting"]; }
              });
              if(window._editor&&window._editor.cm)window._editor.cm.on('change',function(){saveDraft(id);});
              var _pv=document.querySelector('.editormd-preview-container');
              if(_pv&&!_pv._obs){_pv._obs=new MutationObserver(function(){renderPreviewStyles();});_pv._obs.observe(_pv,{childList:true,subtree:true,characterData:true});}
              setTimeout(renderPreviewStyles,300);
              setTimeout(function(){bindPasteHandler()},600);
              ['postTitle','postSlug','postTags','postTime','postPublish','postPassword'].forEach(function(idn){var el=document.getElementById(idn);if(el)el.addEventListener('input',function(){saveDraft(id)});});
              if(window._draftTimer)clearInterval(window._draftTimer);
              window._draftTimer=setInterval(function(){if(window._editor)saveDraft(id);},5000);
              
            }catch(e){console.error('Editor.md init error:',e)}
          }
        },120);
      };
      var loadPost=id?apiFetch('?action=admin_get_post&id='+id).then(function(r){return r.json()}).then(function(p){
        if(p.title){
          document.getElementById('postTitle').value=p.title;
          bindSlugFollow(p.title,p.slug);
          setPostCats(p.categories&&p.categories.length?p.categories:(p.category_id?[p.category_id]:[]));
          document.getElementById('postStatus').value=p.published;
          document.getElementById('postContent').value=p.content;
          var t = p.created_at ? p.created_at.substring(0, 10) : '';
          document.getElementById('postTime').value = t;
          if(p.publish_at){var pa=p.publish_at.replace(' ','T');if(pa.length>16)pa=pa.substring(0,16);document.getElementById('postPublish').value=pa;}
          document.getElementById('postTags').value=(p.tags||[]).join(', ');
          var pwEl=document.getElementById('postPassword');if(pwEl){pwEl.value='';pwEl.placeholder=p.password_set?'当前为私密文章，留空保存将转为公开':'留空公开';}
        }
      }):Promise.resolve();
      loadPost.then(function(){var np=document.getElementById('postPassword');if(np)np.value='';loadCommonPw();restoreDraft(id);if(!id)bindSlugFollow('','');startEditor();});
    });
  }).catch(function(){alert('加载分类失败，请重试')});
}
function restoreDraft(id){
  try{
    var key='miniblog_draft_'+(id||'new');
    var saved=JSON.parse(localStorage.getItem(key)||'null');
    if(!saved||!saved.savedAt||Date.now()-saved.savedAt>=7*24*3600*1000)return;
    var set=function(n,v){var el=document.getElementById(n);if(el&&v!==undefined&&v!==null)el.value=v;};
    set('postTitle',saved.title);set('postSlug',saved.slug);set('postTags',saved.tags);set('postCat',saved.cat||0);set('postStatus',saved.pub||1);set('postTime',saved.time);set('postPublish',saved.publish);
    if(saved.cats&&saved.cats.length){document.querySelectorAll('.postCatCb').forEach(function(cb){cb.checked=saved.cats.indexOf(parseInt(cb.value,10))>=0;});updateMainCat();}
    if(saved.content!==undefined)document.getElementById('postContent').value=saved.content;
    var dh=document.getElementById('draftHint');if(dh){dh.style.display='block';dh.textContent='已恢复本地自动保存草稿';}
  }catch(e){}
}
function saveDraft(id){
  try{
    var key='miniblog_draft_'+(id||'new');
    var content=window._editor&&typeof window._editor.getMarkdown==='function'?window._editor.getMarkdown():document.getElementById('postContent')?.value||'';
    localStorage.setItem(key,JSON.stringify({savedAt:Date.now(),title:document.getElementById('postTitle')?.value||'',slug:document.getElementById('postSlug')?.value||'',tags:document.getElementById('postTags')?.value||'',cat:document.getElementById('postCat')?.value||'',cats:Array.from(document.querySelectorAll('.postCatCb:checked')).map(function(cb){return parseInt(cb.value,10);}),pub:document.getElementById('postStatus')?.value||'',time:document.getElementById('postTime')?.value||'',publish:document.getElementById('postPublish')?.value||'',password:document.getElementById('postPassword')?.value||'',content:content}));
    var h=document.getElementById('draftHint');if(h){h.style.display='block';h.textContent='草稿已自动保存 '+new Date().toLocaleTimeString();}
  }catch(e){}
}
function clearDraft(id){try{localStorage.removeItem('miniblog_draft_'+(id||'new'));}catch(e){}}
function fillCommonPw(v){var e=document.getElementById('postPassword');if(e)e.value=v;}
function applyFontToSelection(v){
  var sel=document.getElementById('editorFontSize');if(sel)sel.value=v;
  var cm=window._editor&&window._editor.cm;if(!cm)return;
  var text=cm.getSelection();if(!text)return;
  cm.replaceSelection('{font:'+v+'}'+text+'{/font}');cm.focus();
}
function applyColorToSelection(v){
  var cm=window._editor&&window._editor.cm;if(!cm)return;
  var text=cm.getSelection();if(!text)return;
  cm.replaceSelection('{color:'+v+'}'+text+'{/color}');cm.focus();
}
var _tagWhitelist=['b','i','u','s','mark','small','sub','sup','code','kbd','strong','em'];
function addTagToSelection(){
  var cm=window._editor&&window._editor.cm;if(!cm)return;
  var text=cm.getSelection();if(!text)return alert('请先选中要加标签的文字');
  var el=document.getElementById('editorTagName');
  var tag=el&&el.value?el.value:'b';
  if(_tagWhitelist.indexOf(tag)===-1)return alert('不支持的标签：'+tag);
  cm.replaceSelection('{tag:'+tag+'}'+text+'{/tag:'+tag+'}');cm.focus();
  setTimeout(renderPreviewStyles,50);
}
function removeTagsFromSelection(){
  var cm=window._editor&&window._editor.cm;if(!cm)return;
  var text=cm.getSelection();if(!text)return alert('请先选中要去标签的文字');
  cm.replaceSelection(text.replace(/\{tag:[a-zA-Z0-9]+\}/g,'').replace(/\{\/tag:[a-zA-Z0-9]+\}/g,''));cm.focus();
  setTimeout(renderPreviewStyles,50);
}
function loadCommonPw(){
  var sel=document.getElementById('commonPwSelect');if(!sel)return;
  apiFetch('?action=admin_common_pw').then(function(r){return r.json()}).then(function(d){
    sel.innerHTML='<option value="">常用密码</option>'+(d.list||[]).map(function(p){return '<option value="'+escapeHtml(p)+'">'+escapeHtml(p)+'</option>'}).join('');
  }).catch(function(){});
}
function saveCommonPw(){
  var pw=document.getElementById('postPassword')?.value||'';
  if(!pw)return alert('请先输入要保存的密码');
  apiFetch('?action=admin_common_pw_add&pwd='+encodeURIComponent(pw)).then(function(r){return r.json()}).then(function(d){if(d.ok){loadCommonPw();alert('已保存为常用密码');}else alert(d.error||'保存失败')}).catch(function(){alert('请求失败，请重试')});
}
function savePost(id, forcePublish){
  var title=document.getElementById('postTitle')?.value.trim();
  if(!title)return alert('请输入标题');
  if(forcePublish!==undefined&&forcePublish!==null){var st=document.getElementById('postStatus');if(st)st.value=forcePublish;}
  var slug=document.getElementById('postSlug')?.value.trim()||'';
  var content='';
  if(window._editor && typeof window._editor.getMarkdown==='function'){ try{content=window._editor.getMarkdown()}catch(e){} }
  if(!content)content=document.getElementById('postContent')?.value||'';
  doSavePost(id,title,slug,content);
}
function doSavePost(id,title,slug,content){
  var cat=document.getElementById('postCat')?.value||0;
  var cats=[];
  document.querySelectorAll('.postCatCb:checked').forEach(function(cb){cats.push(parseInt(cb.value,10));});
  var pub=document.getElementById('postStatus')?.value||1;
  var timeEl=document.getElementById('postTime');
  var timeStr=timeEl ? timeEl.value : '';  // 格式 "2025-01-01"（仅日期）
  var tags=document.getElementById('postTags')?.value.trim()||'';
  var pubEl=document.getElementById('postPublish');
  var publishAt=pubEl?pubEl.value:'';
  var password=document.getElementById('postPassword')?.value||'';
  
  apiFetch('?action=admin_save',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({
      id:id,
      title:title,
      slug:slug,
      content:content,
      categories:cats,
      category_id:cat,
      published:pub,
      created_at:timeStr,   // 留空则使用默认时间
      tags:tags,
      publish_at:publishAt,
      password:password,
    })
  }).then(function(r){return r.json()}).then(function(d){if(d.ok){clearDraft(id);hideModal();if(location.search.includes('admin'))loadPostsList();else location.reload();}else alert(d.error)}).catch(function(){alert('保存失败，请重试')});
}
function deletePost(id){
  if(!confirm('确定删除此文章？评论也将一并删除。'))return;
  apiFetch('?action=admin_delete&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadPostsList()}).catch(function(){alert('删除失败，请重试')});
}
var _allCats=[];
var _catsReady=false;
function loadCats(force){
  if(!force&&_allCats.length){return Promise.resolve(_allCats);}
  _allCats=[];
  return apiFetch('?action=admin_cats').then(function(r){return r.json()}).then(function(d){_allCats=d;_catsReady=true;return d;});
}
function loadCatsList(){
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  loadCats(true).then(function(d){
    _allCats=d;
    var sorted=[];
    (function walk(pid,pre){d.forEach(function(c){if((c.parent_id||0)==pid){c._pre=pre;sorted.push(c);walk(c.id,pre+'\u3000\u2514 ');}})})(0,'');
    var h='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><span style="color:var(--t2);font-size:.85rem">共 '+d.length+' 个分类</span><button onclick="openCatEditor()">\ud83d\udcc2 添加分类</button></div><table class="admin-table"><thead><tr><th>名称</th><th>Slug</th><th>父分类</th><th>文章数</th><th>描述</th><th>操作</th></tr></thead><tbody>';
    sorted.forEach(function(c){
      var pn='-';if(c.parent_id){var p=d.find(function(x){return x.id==c.parent_id});if(p)pn=p.name;}
      h+='<tr><td style="font-weight:600">'+(c._pre||'')+escapeHtml(c.name)+'</td><td style="color:var(--t2);font-size:.82rem">'+escapeHtml(c.slug)+'</td><td style="font-size:.82rem;color:var(--t2)">'+escapeHtml(pn)+'</td><td>'+c.post_count+'</td><td style="color:var(--t2);font-size:.82rem">'+escapeHtml(c.description||'-')+'</td><td><div class="actions"><button onclick="openCatEditor('+c.id+')">\u7f16\u8f91</button><button onclick="deleteCat('+c.id+')">\u5220\u9664</button></div></td></tr>';
    });
    h+='</tbody></table>';app.innerHTML=h;
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function openCatEditor(id){
  id=id||0;
  loadCats(true).then(function(d){
    _allCats=d;
    var sel='<option value="0">\u65e0(\u9876\u7ea7)</option>';
    d.forEach(function(c){if(c.id!=id)sel+='<option value="'+c.id+'">'+escapeHtml(c.name)+'</option>'});
    var html='<h2>'+(id?'\u7f16\u8f91\u5206\u7c7b':'\u6dfb\u52a0\u5206\u7c7b')+'</h2>';
    html+='<label>\u5206\u7c7b\u540d\u79f0</label><input type="text" id="catName" maxlength="50" placeholder="\u5206\u7c7b\u540d\u79f0">';
    html+='<label>\u7236\u5206\u7c7b</label><select id="catParent" style="width:100%;padding:10px 14px;border:1px solid #e0e6ed;border-radius:8px;font-size:.9rem">'+sel+'</select>';
    html+='<label>\u63cf\u8ff0 <span class="form-hint">\u53ef\u9009</span></label><input type="text" id="catDesc" maxlength="100" placeholder="\u7b80\u77ed\u63cf\u8ff0">';
    html+='<div class="modal-actions"><button onclick="hideModal()">\u53d6\u6d88</button><button onclick="saveCat('+id+')">\u4fdd\u5b58</button></div>';
    showModal(html);
    if(id){
      var c=d.find(function(x){return x.id==id});
      if(c){
        var nEl=document.getElementById('catName');if(nEl)nEl.value=c.name;
        var dEl=document.getElementById('catDesc');if(dEl)dEl.value=c.description||'';
        var pEl=document.getElementById('catParent');if(pEl)pEl.value=c.parent_id||0;
      }
    }
    setTimeout(function(){var e=document.getElementById('catName');if(e){e.focus();e.setSelectionRange(0,0);}},100);
  });
}
function saveCat(id){
  var nameEl=document.getElementById('catName');
  var descEl=document.getElementById('catDesc');
  var parentEl=document.getElementById('catParent');
  var name=nameEl?nameEl.value.replace(/[\s\u3000]+/g,'').trim():'';
  if(!name){alert('\u8bf7\u8f93\u5165\u5206\u7c7b\u540d\u79f0');nameEl.focus();return;}
  var desc=descEl?descEl.value.trim():'';
  var pid=parseInt(parentEl?parentEl.value:'0');if(isNaN(pid)||pid<0)pid=0;
  var body=JSON.stringify({id:id,name:name,description:desc,parent_id:pid});
  apiFetch('?action=admin_cat_save',{method:'POST',headers:{'Content-Type':'application/json'},body:body}).then(function(r){return r.json()}).then(function(d){
    if(d.ok){hideModal();loadCatsList();}
    else{alert('\u4fdd\u5b58\u5931\u8d25: '+(d.error||'\u672a\u77e5\u9519\u8bef'));}
  }).catch(function(e){alert('\u8bf7\u6c42\u5931\u8d25: '+e.message);});
}
function deleteCat(id){
  if(!confirm('确定删除此分类？文章将变为未分类'))return;
  apiFetch('?action=admin_cat_delete&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadCatsList()}).catch(function(){alert('删除失败，请重试')});
}
function loadCommentsList(){
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  apiFetch('?action=admin_comments').then(function(r){return r.json()}).then(function(d){
    var comments=(d&&d.comments)||[];var blacklist=(d&&d.blacklist)||[];
if(!comments.length&&!blacklist.length){app.innerHTML='<div class="empty"><div class="empty-icon"><?=ico('message',30)?></div><p>暂无评论</p></div>';return}
    var html='';
    if(blacklist.length){
      html+='<div style="background:var(--card);border-radius:var(--br);padding:16px 20px;box-shadow:var(--s);margin-bottom:14px"><h3 style="font-size:.95rem;margin-bottom:8px">🚫 IP 黑名单</h3>'+blacklist.map(function(b){return '<span class="tag-chip" style="margin:2px">'+escapeHtml(b.ip)+(b.note?' ('+escapeHtml(b.note)+')':'')+' <a href="javascript:void(0)" onclick="blacklistRemove(\''+escapeHtml(b.ip)+'\')" style="color:#ef4444;margin-left:2px">移除</a></span>'}).join('')+'</div>';
    }
    html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><span style="color:var(--t2);font-size:.85rem">共 '+comments.length+' 条评论</span></div><table class="admin-table"><thead><tr><th>文章</th><th>作者</th><th>内容</th><th>IP</th><th>状态</th><th>日期</th><th>操作</th></tr></thead><tbody>';
    comments.forEach(function(c){
      html+='<tr><td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem">'+escapeHtml(c.post_title||'已删除')+'</td><td style="font-weight:600">'+escapeHtml(c.author)+'</td><td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem;color:var(--t2)">'+escapeHtml(c.content)+'</td><td style="font-size:.75rem;color:var(--t3)">'+escapeHtml(c.ip||'-')+'</td><td><span class="status-badge '+(c.approved?'published':'draft')+'">'+(c.approved?'已审核':'待审核')+'</span></td><td style="font-size:.82rem;color:var(--t2)">'+c.created_at+'</td><td><div class="actions">'+(!c.approved?'<button onclick="approveComment('+c.id+')" style="background:#eef2ff;color:var(--b)">通过</button>':'')+(c.ip?'<button onclick="blacklistAdd(\''+escapeHtml(c.ip)+'\')" style="background:#fef2f2;color:#ef4444">拉黑</button>':'')+'<button onclick="deleteComment('+c.id+')" style="background:#fef2f2;color:#ef4444">删除</button></div></td></tr>';
    });
    html+='</tbody></table>';app.innerHTML=html;
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function blacklistAdd(ip){if(!confirm('确定拉黑 '+ip+' 吗？该 IP 将无法评论。'))return;apiFetch('?action=admin_blacklist_add&ip='+encodeURIComponent(ip)).then(function(r){return r.json()}).then(function(d){if(d.ok)loadCommentsList();else alert(d.error||'操作失败')}).catch(function(){alert('请求失败')});}
function blacklistRemove(ip){apiFetch('?action=admin_blacklist_remove&ip='+encodeURIComponent(ip)).then(function(r){return r.json()}).then(function(d){if(d.ok)loadCommentsList();else alert(d.error||'操作失败')}).catch(function(){alert('请求失败')});}
function approveComment(id){apiFetch('?action=admin_comment_approve&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadCommentsList()});}
function deleteComment(id){if(!confirm('确定删除此评论？'))return;apiFetch('?action=admin_comment_delete&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadCommentsList()});}
function cleanupUploads(){
  if(!confirm('确定一键清理全部未被引用的文件吗？文章、头像和系统设置引用的文件会保留，此操作不可恢复！'))return;
  apiFetch('?action=admin_cleanup_uploads').then(function(r){return r.json()}).then(function(d){
    if(d.ok){alert('已清理 '+d.deleted.length+' 个未引用文件'+(d.kept?'，跳过 '+d.kept+' 个文件':''));if(location.search.indexOf('admin=files')>=0)loadFiles();else if(loadSettings)loadSettings();}
    else alert(d.error||'清理失败');
  }).catch(function(){alert('清理请求失败，请重试')});
}
function loadFiles(){
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  apiFetch('?action=admin_files').then(function(r){return r.json()}).then(function(d){
if(!d.files||!d.files.length){app.innerHTML='<div class="empty"><div class="empty-icon"><?=ico('folder',30)?></div><p>暂无上传文件</p></div>';return;}
    var mb=function(b){return (b/1048576).toFixed(2)+' MB';};
    var allFiles=d.files||[];
    var filt=window._fileTypeFilter||'';
    var shown=filt?allFiles.filter(function(f){return (f.type||f.ext||'none')===filt;}):allFiles;
    var unused=allFiles.filter(function(f){return !f.used&&!f.locked;}).length;
    var capInfo=d.maxBytes>0?(' · 单文件上限 '+mb(d.maxBytes)):' · 单文件大小不限';
    var countTxt=filt?('显示 '+shown.length+' / '+d.count+' 个文件'):('共 '+d.count+' 个文件');
    var html='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:8px"><span class="file-summary">'+countTxt+'，占用 '+mb(d.total)+capInfo+'，未引用 '+unused+' 个</span><span><button onclick="cleanupUploads()" class="btn btn-danger" style="padding:7px 14px;border-radius:40px;border:none;color:#fff;background:#f59e0b;font-size:.78rem;cursor:pointer"><?=ico('filter',12)?> 一键清理全部未引用</button><button onclick="batchDeleteFiles()" class="btn btn-danger" style="padding:7px 14px;border-radius:40px;border:none;color:#fff;background:#ef4444;font-size:.78rem;cursor:pointer;margin-left:8px"><?=ico('trash',12)?> 批量删除</button></span></div>';
    var extHtml='<span class="tag-chip'+(filt?'':' active')+'" style="cursor:pointer" onclick="filterFilesByType(\'\')">全部</span>';
    if(d.byExt){for(var k in d.byExt){extHtml+='<span class="tag-chip'+(filt===k?' active':'')+'" style="cursor:pointer" onclick="filterFilesByType(\''+k+'\')">'+escapeHtml(k)+' '+mb(d.byExt[k])+'</span>';}}
    html+='<div class="card-tags" style="margin:8px 0">'+extHtml+'</div>';
    html+='<table class="admin-table"><thead><tr><th style="width:40px"><input type="checkbox" onchange="toggleAllFiles(this)"></th><th>文件名</th><th>类型</th><th>大小</th><th>修改时间</th><th>引用</th><th>操作</th></tr></thead><tbody>';
    shown.forEach(function(f){
      var chk=(f.used||f.locked)?'':('<input type="checkbox" class="fileSel" value="'+escapeHtml(f.name)+'">');
      var op;
      if(f.used)op='<span style="color:var(--t3);font-size:.75rem">不可删除</span>';
      else if(f.locked)op='<button onclick="lockUploadFile(\''+escapeHtml(f.name)+'\',0)" style="background:#eef2ff;color:var(--b);border:none;border-radius:8px;padding:5px 12px;cursor:pointer">解锁</button>';
      else op='<button onclick="lockUploadFile(\''+escapeHtml(f.name)+'\',1)" style="background:#fefce8;color:#a16207;border:none;border-radius:8px;padding:5px 12px;cursor:pointer">锁定</button> <button onclick="deleteUploadFile(\''+escapeHtml(f.name)+'\')" style="background:#fef2f2;color:#ef4444;border:none;border-radius:8px;padding:5px 12px;cursor:pointer">删除</button>';
      var badge=f.used?'<span class="status-badge published">引用中</span>':(f.locked?'<span class="status-badge" style="background:#fef3c7;color:#b45309">已锁定</span>':'<span class="status-badge draft">未引用</span>');
      html+='<tr><td style="text-align:center">'+chk+'</td><td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace;font-size:.8rem"><a class="file-link" href="./uploads/'+encodeURIComponent(f.name)+'" target="_blank" rel="noopener" title="点击打开：'+escapeHtml(f.name)+'" style="display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom">'+escapeHtml(f.name)+'</a></td><td style="font-size:.8rem" title="'+(f.mime?escapeHtml(f.mime):'')+'">'+escapeHtml(f.type||f.ext||'none')+'</td><td style="font-size:.82rem">'+mb(f.size)+'</td><td style="font-size:.82rem">'+f.mtime+'</td><td>'+badge+'</td><td>'+op+'</td></tr>';
    });
    if(!shown.length){html+='<tr><td colspan="7" style="text-align:center;color:var(--t3);padding:24px;font-size:.85rem">该类型暂无文件</td></tr>';}
    html+='</tbody></table>';
    app.innerHTML=html;
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function filterFilesByType(t){window._fileTypeFilter=t;loadFiles();}
function deleteUploadFile(name){
  if(!confirm('确定删除文件 '+name+' 吗？'))return;
  apiFetch('?action=admin_file_delete&name='+encodeURIComponent(name)).then(function(r){return r.json()}).then(function(d){if(d.ok)loadFiles();else alert(d.error||'删除失败')}).catch(function(){alert('删除失败，请重试')});
}
function lockUploadFile(name,lock){
  apiFetch('?action=admin_file_lock&name='+encodeURIComponent(name)+'&lock='+(lock?1:0)).then(function(r){return r.json()}).then(function(d){if(d.ok)loadFiles();else alert(d.error||'操作失败')}).catch(function(){alert('请求失败，请重试')});
}
function toggleAllFiles(cb){document.querySelectorAll('.fileSel').forEach(function(c){c.checked=cb.checked;});}
function batchDeleteFiles(){
  var names=[];document.querySelectorAll('.fileSel:checked').forEach(function(c){names.push(c.value);});
  if(!names.length)return alert('请先勾选未引用的文件');
  if(!confirm('确定删除选中的 '+names.length+' 个未引用文件吗？'))return;
  apiFetch('?action=admin_files_batch_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({files:names})}).then(function(r){return r.json()}).then(function(d){
    if(d.ok){alert('已删除 '+d.deleted.length+' 个'+(d.skipped&&d.skipped.length?'，跳过被引用 '+d.skipped.length+' 个':''));loadFiles();}
    else alert(d.error||'删除失败');
  }).catch(function(){alert('请求失败，请重试')});
}
function loadSettings(){
  var app=document.getElementById('adminApp');if(!app)return;
  app.innerHTML='<div class="loading"><span class="spinner"></span>加载中...</div>';
  apiFetch('?action=admin_settings').then(function(r){return r.json()}).then(function(d){
    var c1='<div class="settings-card"><h2><?=gearIcon(16,'var(--b)')?>系统设置</h2><h3 class="settings-sub">站点信息</h3><label>站点名称</label><input type="text" id="sSiteName" value="'+escapeHtml(d.site_name)+'"><label>站点字体</label><select id="sSiteFont"><option value="web"'+(d.site_font==='web'?' selected':'')+'>✍️ 网页手写体 · 马善政（全端一致）</option><option value="great"'+(d.site_font==='great'?' selected':'')+'>✒️ 花体 · Great Vibes（全端一致）</option><option value="allura"'+(d.site_font==='allura'?' selected':'')+'>✒️ 花体 · Allura（全端一致）</option><option value="dancing"'+(d.site_font==='dancing'?' selected':'')+'>✍️ 手写 · Dancing Script（全端一致）</option><option value="script"'+(d.site_font==='script'?' selected':'')+'>手写体 · Segoe Script</option><option value="script2"'+(d.site_font==='script2'?' selected':'')+'>古典花体 · Monotype Corsiva</option><option value="script3"'+(d.site_font==='script3'?' selected':'')+'>手写印刷 · Segoe Print</option><option value="script4"'+(d.site_font==='script4'?' selected':'')+'>圆润卡通 · Comic Sans</option><option value="script5"'+(d.site_font==='script5'?' selected':'')+'>签名花体 · Lucida Handwriting</option><option value="script6"'+(d.site_font==='script6'?' selected':'')+'>典雅花体 · Edwardian Script</option><option value="serif"'+(d.site_font==='serif'?' selected':'')+'>衬线体（宋体/Georgia）</option><option value="kai"'+(d.site_font==='kai'?' selected':'')+'>楷体</option><option value="sans"'+(d.site_font==='sans'?' selected':'')+'>现代无衬线</option></select><label>站点描述</label><input type="text" id="sSiteDesc" value="'+escapeHtml(d.site_desc||'')+'"><label>页脚版权文字（支持HTML）</label><input type="text" id="sCopyright" value="'+escapeHtml(d.site_copyright||'')+'"><div style="margin-top:14px"><button onclick="saveSiteSettings()" class="btn btn-primary"><?=ico('save',14)?> 保存站点设置</button></div></div>';
    var c1d='<div class="settings-card"><h2><?=ico('key',15)?>管理员密码</h2><div style="display:flex;gap:8px;align-items:center"><input type="password" id="sNewPass" placeholder="新密码，留空不修改" autocomplete="new-password" style="flex:1"><button onclick="savePassword()" class="btn btn-primary" style="flex:none;padding:10px 16px">保存新密码</button></div><div class="hint">点击“保存新密码”立即生效</div></div>';
    var c1b='<div class="settings-card"><h2><?=ico('upload',15)?>上传设置</h2><label>上传大小限制（MB，0=不限）</label><input type="number" id="sUploadMb" min="0" max="10240" value="'+d.upload_max_mb+'" style="width:150px"><label>允许上传后缀（逗号分隔）</label><input type="text" id="sUploadExts" value="'+escapeHtml(d.upload_exts)+'" placeholder="jpg,png,rar,zip,txt"><div style="margin-top:12px"><button onclick="saveUploadSettings()" class="btn btn-primary"><?=ico('save',15)?> 保存上传设置</button></div></div>';
    var c1c='<div class="settings-card"><h2><?=ico('clipboard',15)?>操作日志</h2><button onclick="loadLogs()" class="btn btn-secondary">查看最近操作</button><div id="logsBox" style="margin-top:10px"></div></div>';
    var c2='<div class="settings-card"><h2><?=ico('bell',15)?>通知与评论过滤</h2><label>通知邮箱</label><input type="email" id="sNotifyEmail" value="'+escapeHtml(d.notify_email||'')+'" placeholder="留空不启用"><label>Server酱 Webhook</label><input type="url" id="sWebhook" value="'+escapeHtml(d.notify_webhook||'')+'" placeholder="https://sctapi.ftqq.com/KEY.send"><label>Telegram Bot Token</label><input type="text" id="sTgBot" value="'+escapeHtml(d.telegram_bot||'')+'" placeholder="123456789:AAF..."><label>Telegram Chat ID</label><input type="text" id="sTgChat" value="'+escapeHtml(d.telegram_chat||'')+'" placeholder="聊天或群组 ID"><label>评论敏感词（逗号分隔）</label><textarea id="sKeywords" style="min-height:80px">'+escapeHtml(d.comment_keywords||'')+'</textarea></div>';
    var c3='<div class="settings-card"><h2><?=ico('archive',15)?>备份与恢复</h2><label>服务器备份目录</label><input type="text" id="sBackupDir" value="'+escapeHtml(d.backup_dir||'')+'" placeholder="绝对路径或相对路径，留空默认 data/backup"><div class="hint">当前备份目录：'+escapeHtml(d.backup_path||'')+'</div>'+(d.backup_dir_ok===false?'<div style="color:#ef4444;font-size:.78rem;margin-top:6px"><?=ico('alert',12)?> '+escapeHtml(d.backup_dir_err||'备份目录不可用')+'</div>':'')+'<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"><button onclick="saveBackupDir()" class="btn btn-primary"><?=ico('save',15)?> 保存目录</button><button onclick="testBackupDir()" class="btn btn-secondary"><?=ico('flask',15)?> 测试目录</button></div><div id="backupTestBox" style="font-size:.75rem;color:var(--t3);white-space:pre-wrap;display:none;margin-top:8px"></div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"><button onclick="downloadBackup()" class="btn btn-primary"><?=ico('download',15)?> 下载备份</button><button onclick="serverBackup()" class="btn btn-secondary"><?=ico('save',15)?> 立即备份到服务器</button></div><div class="quota-bar" id="backupBar" style="display:none"><div style="width:0%"></div></div><div id="backupPct" style="font-size:.75rem;color:var(--t3);margin-top:4px"></div><div id="backupServerMsg" style="font-size:.75rem;color:var(--t3);margin-top:6px"></div><div id="backupStatusBanner" style="display:none;background:#fff7ed;color:#b45309;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px;font-size:.78rem;margin:8px 0"></div><div id="backupListBox" style="margin-top:10px"></div><label>恢复备份（点“下载备份”得到的 .zip 完整包，或 .db 数据库；服务器快照直接用列表里的“恢复”按钮）</label><input type="file" id="restoreFile" accept=".zip,.db,.sqlite"><div class="quota-bar" id="restoreBar" style="display:none"><div style="width:0%"></div></div><div id="restorePct" style="font-size:.75rem;color:var(--t3);margin-top:4px"></div><button onclick="restoreBackup()" class="btn btn-secondary" style="margin-top:8px">恢复数据</button></div>';
    var c1e='<div class="settings-card"><h2><?=ico('file-text',15)?>文章链接导入</h2><label>粘贴其它网站的文章链接（每行一个，最多 10 个）</label><textarea id="sImportUrls" rows="4" placeholder="https://example.com/article/1&#10;https://example.com/article/2" style="width:100%;padding:10px 12px;border:1px solid #e0e6ed;border-radius:10px;background:var(--card);color:var(--t1);font-size:.85rem;box-sizing:border-box;resize:vertical"></textarea><div style="display:flex;gap:8px;align-items:center;margin-top:10px"><button onclick="importUrls()" class="btn btn-primary"><?=ico('download',14)?> 抓取并生成草稿</button></div><div id="sImportResult" style="margin-top:10px;font-size:.78rem;line-height:1.8;word-break:break-all"></div><div class="hint">抓取结果自动保存为草稿，可在「文章」中编辑后发布</div></div>';
    app.innerHTML='<div class="settings-grid"><div class="settings-col">'+c1+c1b+c2+'</div><div class="settings-col" id="settingsCol2">'+c1d+c3+c1e+c1c+'</div></div>';
    injectAuthorSettings(d);
    fixUploadLimitInput();
    loadBackupList();
    loadBackupStatus();
    setupBackupListToggle();
  }).catch(function(){if(app)app.innerHTML='<div class="empty">加载失败</div>';});
}
function injectAuthorSettings(d){
  var col2=document.getElementById('settingsCol2');if(!col2)return;
  var card=document.createElement('div');card.className='settings-card';
  card.innerHTML='<h2><?=ico('user',15)?>作者信息（首页侧栏卡片）</h2>'+
    '<label>作者名称</label><input type="text" id="sAuthorName" value="'+escapeHtml(d.author_name||'')+'" placeholder="留空则显示站点名称">'+
    '<label>作者简介</label><input type="text" id="sAuthorBio" value="'+escapeHtml(d.author_bio||'')+'" placeholder="一句话介绍，可留空">'+
    '<label>作者头像</label>'+
    '<div style="display:flex;gap:10px;align-items:center">'+
'<div id="authorAvatarPreview" style="width:52px;height:52px;border-radius:50%;overflow:hidden;flex:none;background:linear-gradient(135deg,var(--g1),var(--g2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:1.3rem"></div>'+
      '<input type="text" id="sAuthorAvatar" value="'+escapeHtml(d.author_avatar||'')+'" placeholder="./uploads/xxx.webp 或完整 URL" style="flex:1">'+
    '</div>'+
    '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">'+
'<button onclick="uploadAuthorAvatar()" class="btn btn-secondary"><?=ico('upload',13)?> 上传头像</button>'+
'<button onclick="clearAuthorAvatar()" class="btn btn-secondary"><?=ico('trash',13)?> 清除</button>'+
      '<button onclick="saveAuthorSettings()" class="btn btn-primary"><?=ico('save',14)?> 保存作者信息</button>'+
    '</div>'+
    '<div class="hint">头像固定保存为 uploads/avatar.webp，重复上传直接覆盖；文件管理里显示“不可删除”</div>';
  col2.insertBefore(card, col2.firstChild);
  refreshAuthorAvatarPreview();
  document.getElementById('sAuthorName').oninput=refreshAuthorAvatarPreview;
  document.getElementById('sAuthorAvatar').oninput=refreshAuthorAvatarPreview;
}
function refreshAuthorAvatarPreview(){
  var box=document.getElementById('authorAvatarPreview');if(!box)return;
  var v=(document.getElementById('sAuthorAvatar')?.value||'').trim();
  var name=(document.getElementById('sAuthorName')?.value||'').trim()||'B';
  if(v){box.style.background='none';box.innerHTML='<img src="'+escapeHtml(v)+'" style="width:100%;height:100%;object-fit:cover;display:block">';}
else{box.style.background='linear-gradient(135deg,var(--g1),var(--g2))';box.innerHTML=escapeHtml(name.charAt(0).toUpperCase());}
}
function uploadAuthorAvatar(){
  var input=document.createElement('input');input.type='file';input.accept='image/*';
  input.onchange=function(){
    var file=input.files[0];if(!file)return;
    var fd=new FormData();fd.append('file',file);
    var xhr=new XMLHttpRequest();
    xhr.onload=function(){
      try{var d=JSON.parse(xhr.responseText)}catch(e){alert('上传失败');return}
      if(d.url){var box=document.getElementById('sAuthorAvatar');if(box){box.value=d.url;refreshAuthorAvatarPreview();alert('✅ 头像已更新并自动保存');}}
      else alert(d.error||'上传失败');
    };
    xhr.onerror=function(){alert('上传失败，请重试')};
    xhr.open('POST','?action=admin_upload&type=image&as=avatar');
    xhr.setRequestHeader('X-CSRF-Token',window.CSRF);
    xhr.send(fd);
  };
  input.click();
}
function clearAuthorAvatar(){var box=document.getElementById('sAuthorAvatar');if(box){box.value='';refreshAuthorAvatarPreview();}}
function saveAuthorSettings(){
  var name=(document.getElementById('sAuthorName')?.value||'').trim();
  var bio=(document.getElementById('sAuthorBio')?.value||'').trim();
  var avatar=(document.getElementById('sAuthorAvatar')?.value||'').trim();
  apiFetch('?action=admin_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({author_name:name,author_bio:bio,author_avatar:avatar})}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('✅ 作者信息已保存');location.reload();}else alert(d.error||'保存失败')}).catch(function(){alert('保存失败，请重试')});
}
function serverBackup(){
  if(window._backupRunning)return alert('正在备份中，请稍候');
  var msg=document.getElementById('backupServerMsg');if(msg)msg.textContent='正在备份到服务器...';
  apiFetch('?action=admin_backup').then(function(r){return r.json()}).then(function(d){
    if(d.ok){window._backupRunning=false;if(msg)msg.textContent='已生成增量备份：'+d.file+'（新增 '+d.changed+' 个文件，完整 '+(d.full_size/1048576).toFixed(2)+' MB / '+d.full_count+' 个，目录：'+d.root+'）';loadBackupList();loadBackupStatus();}
    else if(d.running){if(msg)msg.textContent='';alert(d.error||'正在备份中，请稍候');}
    else{if(msg)msg.textContent='';alert(d.error||'备份失败');}
  }).catch(function(){if(msg)msg.textContent='';alert('备份请求失败，请重试');});
}
function loadBackupStatus(){
  var banner=document.getElementById('backupStatusBanner');if(!banner)return;
  apiFetch('?action=admin_backup_status').then(function(r){return r.json()}).then(function(d){
    window._backupRunning=!!(d&&d.running);
    if(window._backupRunning){
      window._backupWasRunning=true;
      banner.style.display='block';
      banner.textContent='⏳ 正在备份中（开始于 '+(d.since||'')+'），请勿关闭页面或再次触发备份';
      clearTimeout(window._backupStatusTimer);
      window._backupStatusTimer=setTimeout(loadBackupStatus,5000);
    }else{
      banner.style.display='none';
      clearTimeout(window._backupStatusTimer);
      if(window._backupWasRunning){window._backupWasRunning=false;if(loadBackupList)loadBackupList();}
    }
  }).catch(function(){});
}
function testBackupDir(){
  var dir=document.getElementById('sBackupDir')?.value.trim()||'';
  var box=document.getElementById('backupTestBox');if(!box)return;
  box.style.display='block';box.textContent='正在测试，请稍候...';
  apiFetch('?action=admin_backup_test&dir='+encodeURIComponent(dir)).then(function(r){return r.json()}).then(function(d){
    if(d.error){box.textContent='错误：'+d.error;return;}
    var lines=[];
    lines.push('目录：'+d.dir);
    lines.push('目录存在：'+(d.exists?'是':'否'));
    lines.push('可写：'+(d.writable?'是':'否'));
    if(d.mkdir_ok!==undefined)lines.push('自动创建：'+(d.mkdir_ok?'成功':'失败')+(d.mkdir_error?'（'+d.mkdir_error+'）':''));
    lines.push('写入测试：'+(d.write_test?'成功':'失败')+(d.write_error?'（'+d.write_error+'）':''));
    lines.push('PHP 用户：'+(d.php_user||'未知'));
    lines.push('open_basedir：'+(d.open_basedir||'(不限)'));
    lines.push('可用空间：'+(d.disk_free?Math.round(d.disk_free/1048576)+' MB':'未知'));
    box.textContent=lines.join('\n');
  }).catch(function(e){box.textContent='测试请求失败：'+e.message;});
}
function saveBackupDir(){
  var bd=document.getElementById('sBackupDir')?.value.trim()||'';
  apiFetch('?action=admin_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({backup_dir:bd})}).then(function(r){return r.json()}).then(function(d){
    if(d.ok){alert('备份目录已保存');loadSettings();}
    else alert(d.error||'保存失败');
  }).catch(function(){alert('保存失败，请重试')});
}
function setupBackupListToggle(){
  var box=document.getElementById('backupListBox');if(!box)return;
  if(document.getElementById('backupListToggle'))return;
  var tg=document.createElement('div');
  tg.id='backupListToggle';
  tg.style.cssText='display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.82rem;font-weight:600;color:#fff;margin-top:12px;padding:9px 12px;border:none;border-radius:10px;background:linear-gradient(135deg,var(--g1),var(--g2));box-shadow:0 2px 8px rgba(0,0,0,.15);user-select:none;text-align:center';
  tg.innerHTML='备份列表 <span id="backupListArrow" style="margin-left:2px">▾</span>';
  tg.onclick=toggleBackupList;
  box.parentNode.insertBefore(tg,box);
  box.style.display='none';
}
function toggleBackupList(){
  var box=document.getElementById('backupListBox');var arr=document.getElementById('backupListArrow');
  if(!box)return;
  var hidden=box.style.display==='none';
  box.style.display=hidden?'':'none';
  if(arr)arr.textContent=hidden?'▴':'▾';
}
function loadBackupList(){
  var box=document.getElementById('backupListBox');if(!box)return;
  box.innerHTML='<div style="color:var(--t3);font-size:.8rem">加载中...</div>';
  apiFetch('?action=admin_backup_list').then(function(r){return r.json()}).then(function(d){
    if(!d.files||!d.files.length){box.innerHTML='<div style="color:var(--t3);font-size:.8rem">暂无服务器备份</div>';return;}
    var mb=function(b){return (b/1048576).toFixed(2)+' MB';};
    var head='<div style="margin-bottom:8px;font-size:.78rem;color:var(--t2)">完整备份：'+mb(d.full_size||0)+' / '+(d.full_count||0)+' 个文件'+(d.complete?'':'，<?=ico('alert',12)?> 缺少 '+(d.missing||0)+' 个文件，请重新生成')+'</div>';
    box.innerHTML=head+d.files.map(function(f){var acts='<a href="javascript:void(0)" onclick="restoreServerBackup(\''+escapeHtml(f.name)+'\')" style="display:inline-flex;align-items:center;padding:4px 13px;border-radius:40px;background:#eef2ff;color:var(--b);font-size:.72rem;text-decoration:none">恢复</a><a href="?action=admin_backup_download&file='+encodeURIComponent(f.name)+'&token='+window.CSRF+'" style="display:inline-flex;align-items:center;padding:4px 13px;border-radius:40px;background:#f1f4f9;color:var(--t1);font-size:.72rem;text-decoration:none">下载</a><a href="javascript:void(0)" onclick="deleteBackupFile(\''+escapeHtml(f.name)+'\')" style="display:inline-flex;align-items:center;padding:4px 13px;border-radius:40px;background:#fef2f2;color:#ef4444;font-size:.72rem;text-decoration:none">删除</a>';return '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 12px;border:1px solid rgba(0,0,0,.06);border-radius:10px;background:rgba(0,0,0,.02);margin-bottom:8px;font-size:.78rem"><div style="flex:1;min-width:0"><div title="'+escapeHtml(f.name)+'" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+escapeHtml(f.name)+'</div><div style="color:var(--t3);font-size:.72rem;margin-top:2px">'+mb(f.size)+' · '+(f.mtime||'')+'</div></div><span style="display:flex;gap:6px;flex:none">'+acts+'</span></div>';}).join('');
  }).catch(function(){box.innerHTML='<div style="color:var(--t3);font-size:.8rem">加载失败</div>';});
}
function deleteBackupFile(name){if(!confirm('确定删除服务器备份 '+name+' 吗？'))return;apiFetch('?action=admin_backup_delete&file='+encodeURIComponent(name)).then(function(r){return r.json()}).then(function(d){if(d.ok)loadBackupList();else alert(d.error||'删除失败')}).catch(function(){alert('请求失败，请重试')});}
function restoreServerBackup(name){
  if(!confirm('确定从服务器快照 '+name+' 恢复整个站点？将覆盖 index.php、editormd、data、uploads。'))return;
  apiFetch('?action=admin_restore_server&file='+encodeURIComponent(name)).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('恢复成功，页面即将刷新');location.reload();}else alert(d.error||'恢复失败')}).catch(function(){alert('恢复请求失败，请重试')});
}
function downloadBackup(){
  if(window._backupRunning)return alert('正在备份中，请稍候');
  var bar=document.getElementById('backupBar');var txt=document.getElementById('backupPct');
  if(bar){bar.style.display='block';bar.classList.add('indeterminate');bar.querySelector('div').style.width='40%';}
  if(txt)txt.textContent='正在生成增量备份...（首次可能较久，请勿关闭页面）';
  fetch('?action=admin_backup&token='+window.CSRF).then(function(r){return r.json()}).then(function(d){
    if(!d.ok)throw new Error(d.running?(d.error||'正在备份中，请稍候'):(d.error||'备份创建失败'));
    if(!d.file)throw new Error('备份文件不存在');
    if(bar){bar.classList.remove('indeterminate');bar.querySelector('div').style.width='0%';}
    if(txt)txt.textContent='正在下载完整备份...';
    return fetch('?action=admin_backup_download&file='+encodeURIComponent(d.file)+'&token='+window.CSRF).then(function(r2){
      if(!r2.ok)throw new Error('下载失败 HTTP '+r2.status);
      var total=parseInt(r2.headers.get('Content-Length')||'0',10);
      var received=0;var chunks=[];var reader=r2.body.getReader();
      function pump(){
        return reader.read().then(function(res){
          if(res.done)return;
          received+=res.value.length;chunks.push(res.value);
          if(bar)bar.querySelector('div').style.width=Math.min(100,total?received/total*100:50)+'%';
          if(txt)txt.textContent=total?Math.round(received/total*100)+'%':Math.round(received/1048576*10)/10+' MB';
          return pump();
        });
      }
      return pump().then(function(){return new Blob(chunks,{type:'application/zip'});});
    });
  }).then(function(blob){
    var a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='miniblog-backup-'+new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')+'.zip';
    document.body.appendChild(a);a.click();a.remove();
    if(bar){bar.classList.remove('indeterminate');bar.querySelector('div').style.width='100%';}
    if(txt)txt.textContent='完成';
  }).catch(function(e){
    if(bar)bar.classList.remove('indeterminate');
    if(txt)txt.textContent='失败';
    alert(e.message||'备份下载失败，请重试');
  });
}
function restoreBackup(){
  var f=document.getElementById('restoreFile');
  if(!f||!f.files||!f.files[0])return alert('请选择备份文件');
  var ext=(f.files[0].name.split('.').pop()||'').toLowerCase();
  if(['zip','db','sqlite'].indexOf(ext)===-1)return alert('请选择 .zip 完整备份包或 .db 数据库文件');
  if(!confirm('恢复会覆盖当前数据库和上传文件，确定继续？'))return;
  var bar=document.getElementById('restoreBar');var txt=document.getElementById('restorePct');
  if(bar)bar.style.display='block';if(txt)txt.textContent='0%';
  var fd=new FormData();fd.append('file',f.files[0]);
  var xhr=new XMLHttpRequest();
  xhr.open('POST','?action=admin_restore');
  xhr.setRequestHeader('X-CSRF-Token',window.CSRF);
  xhr.upload.onprogress=function(e){
    if(e.lengthComputable&&bar)bar.querySelector('div').style.width=Math.round(e.loaded/e.total*100)+'%';
    if(txt)txt.textContent=e.lengthComputable?(e.loaded===e.total?'处理中，请勿关闭页面...':Math.round(e.loaded/e.total*100)+'%'):'处理中...';
  };
  xhr.onload=function(){
    var d={};try{d=JSON.parse(xhr.responseText);}catch(e){}
    if(d.ok){if(txt)txt.textContent='完成，正在刷新...';alert('恢复成功，页面即将刷新');location.reload();}
    else{alert((d.error||'恢复失败')+(d.error?'':' HTTP '+xhr.status));if(txt)txt.textContent='失败';}
  };
  xhr.onerror=function(){if(txt)txt.textContent='失败';alert('恢复请求失败，请重试');};
  xhr.send(fd);
}
function loadLogs(){
  var box=document.getElementById('logsBox');if(!box)return;
  if(box.innerHTML!==''){box.innerHTML='';return;}
  apiFetch('?action=admin_logs').then(function(r){return r.json()}).then(function(d){
    var rows=(d&&d.logs)||[];
    var html='';
    if(d&&d.server_now)html+='<div style="color:var(--t3);font-size:.75rem;margin-bottom:6px">服务器当前时间（北京时间）：'+escapeHtml(d.server_now)+'</div>';
    function parseDt(s){var m=s&&s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);if(!m)return 0;return new Date(+m[1],+m[2]-1,+m[3],+m[4],+m[5],+m[6]).getTime();}
    if(d&&d.last_created&&d.server_now&&(parseDt(d.server_now)-parseDt(d.last_created))/3600000>7){
      html+='<div style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:8px;padding:8px 12px;font-size:.75rem;margin-bottom:8px">检测到日志时间仍比服务器慢 7 小时以上（可能是 UTC），<a href="javascript:void(0)" onclick="calibrateLogs()" style="color:#b91c1c;font-weight:700">点这里一键校准 +8 小时</a></div>';
    }
    if(!rows.length){box.innerHTML=html+'<div style="color:var(--t3);font-size:.82rem">暂无日志</div>';return;}
html+='<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><button onclick="clearLogs()" style="padding:5px 12px;border-radius:8px;border:1px solid #e0e6ed;background:#fef2f2;color:#ef4444;font-size:.75rem;cursor:pointer"><?=ico('trash',12)?> 清空日志</button></div><div style="max-height:260px;overflow:auto;font-size:.78rem">'+rows.map(function(l){return '<div style="display:flex;justify-content:space-between;gap:8px;padding:5px 0;border-bottom:1px solid rgba(0,0,0,.05)"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><b>'+escapeHtml(l.action)+'</b> '+escapeHtml(l.detail||'')+' <span style="color:var(--t3)">'+escapeHtml(l.created_at)+'</span></span><a href="javascript:void(0)" onclick="deleteLog('+l.id+')" style="color:#ef4444;flex:none">删除</a></div>'}).join('')+'</div>';
    box.innerHTML=html;
  }).catch(function(){box.innerHTML='<div style="color:var(--t3)">加载失败</div>';});
}
function collapseLogs(){var box=document.getElementById('logsBox');if(box)box.innerHTML='';}
function calibrateLogs(){if(!confirm('确定把所有日志时间 +8 小时？（仅当日志仍是 UTC 时使用）'))return;apiFetch('?action=admin_logs_tz_fix').then(function(r){return r.json()}).then(function(d){if(d.ok){alert('已校准');loadLogs();}else alert(d.error||'校准失败')}).catch(function(){alert('请求失败，请重试')});}
function deleteLog(id){if(!confirm('确定删除这条日志？'))return;apiFetch('?action=admin_logs_delete&id='+id).then(function(r){return r.json()}).then(function(d){if(d.ok)loadLogs();else alert(d.error||'删除失败')}).catch(function(){alert('请求失败，请重试')});}
function clearLogs(){if(!confirm('确定清空全部操作日志？'))return;apiFetch('?action=admin_logs_delete').then(function(r){return r.json()}).then(function(d){if(d.ok)loadLogs();else alert(d.error||'清空失败')}).catch(function(){alert('请求失败，请重试')});}
function saveSiteSettings(){
  var name=document.getElementById('sSiteName')?.value.trim();if(!name)return alert('站点名称不能为空');
  var desc=document.getElementById('sSiteDesc')?.value.trim()||'';
  var copyright=document.getElementById('sCopyright')?.value.trim()||'';
  var font=document.getElementById('sSiteFont')?.value||'great';
  apiFetch('?action=admin_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({site_name:name,site_desc:desc,site_copyright:copyright,site_font:font})}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('✅ 站点设置已保存');location.reload();}else alert(d.error||'保存失败')}).catch(function(){alert('保存失败，请重试')});
}
function fixUploadLimitInput(){
  var el=document.getElementById('sUploadMb');if(!el)return;
  el.min='0';el.max='10240';
  if(el.nextSibling&&el.nextSibling.className==='hint')return;
  el.insertAdjacentHTML('afterend','<div class="hint">填 0 表示不限制单文件大小（受服务器 PHP 配置上限约束）</div>');
}
function saveUploadSettings(){
  var mb=document.getElementById('sUploadMb')?.value;
  var exts=document.getElementById('sUploadExts')?.value.trim()||'';
  if(mb===''||mb===null||isNaN(parseInt(mb,10))||parseInt(mb,10)<0)return alert('请输入有效的上传大小');
  apiFetch('?action=admin_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({upload_max_mb:parseInt(mb,10),upload_exts:exts})}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('✅ 上传设置已保存');loadSettings();}else alert(d.error||'保存失败')}).catch(function(){alert('保存失败，请重试')});
}
function savePassword(){
  var pass=document.getElementById('sNewPass')?.value.trim()||'';
  if(!pass)return alert('请输入新密码');
  apiFetch('?action=admin_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({new_pass:pass})}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('✅ 新密码已保存');document.getElementById('sNewPass').value='';loadSettings();}else alert(d.error||'保存失败')}).catch(function(){alert('保存失败，请重试')});
}
function removeTagGlobal(){
  var tag=(document.getElementById('sRemoveTag')?.value||'').trim();
  if(!tag)return alert('请输入要移除的标签名');
  if(!confirm('确定从所有文章中移除标签 '+tag+' 吗？'))return;
  apiFetch('?action=admin_remove_tag_global',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tag:tag})}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('✅ 已移除标签 '+tag+(d.removed?'（'+d.removed+' 篇受影响）':'（没有文章使用）'));var e=document.getElementById('sRemoveTag');if(e)e.value='';}else alert(d.error||'操作失败')}).catch(function(){alert('请求失败，请重试')});
}
function importUrls(){
  var ta=document.getElementById('sImportUrls');if(!ta)return;
  var urls=ta.value.split(/\r?\n/).map(function(s){return s.trim()}).filter(function(s){return s!==''});
  if(!urls.length)return alert('请先粘贴文章链接');
  if(urls.length>10)return alert('一次最多导入 10 个链接');
  var box=document.getElementById('sImportResult');
  if(box)box.innerHTML='<span style="color:var(--t3)">正在抓取 '+urls.length+' 个链接，请稍候...</span>';
  apiFetch('?action=admin_import_urls',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({urls:urls})}).then(function(r){return r.json()}).then(function(d){
    if(!box)return;
    if(!d.ok){box.innerHTML='<span style="color:#ef4444">'+(d.error||'导入失败')+'</span>';return;}
    var html='';
    (d.results||[]).forEach(function(r){
      if(r.ok)html+='<div style="color:#16a34a">✅ '+escapeHtml(r.title)+' <span style="color:var(--t3)">→ 草稿 #'+r.id+'</span></div>';
      else html+='<div style="color:#ef4444">❌ '+escapeHtml(r.url)+' — '+(r.error||'失败')+'</div>';
    });
    box.innerHTML=html;
    ta.value='';
  }).catch(function(){if(box)box.innerHTML='<span style="color:#ef4444">请求失败，请重试</span>'});
}
function saveSettings(){
  var name=document.getElementById('sSiteName')?.value.trim();if(!name)return alert('站点名称不能为空');
  var desc=document.getElementById('sSiteDesc')?.value.trim()||'';
  var pass=document.getElementById('sNewPass')?.value.trim()||'';
  var copyright=document.getElementById('sCopyright')?.value.trim()||'';
  var ne=document.getElementById('sNotifyEmail')?.value.trim()||'';
  var nw=document.getElementById('sWebhook')?.value.trim()||'';
  var ck=document.getElementById('sKeywords')?.value.trim()||'';
  var tb=document.getElementById('sTgBot')?.value.trim()||'';
  var tc=document.getElementById('sTgChat')?.value.trim()||'';
  var bd=document.getElementById('sBackupDir')?.value.trim()||'';
  var upMb=document.getElementById('sUploadMb')?.value||'';
  var upExts=document.getElementById('sUploadExts')?.value.trim()||'';
  var body={site_name:name,site_desc:desc,site_copyright:copyright,notify_email:ne,notify_webhook:nw,comment_keywords:ck,telegram_bot:tb,telegram_chat:tc,backup_dir:bd};if(pass)body.new_pass=pass;if(upMb&&parseInt(upMb,10)>=1)body.upload_max_mb=parseInt(upMb,10);body.upload_exts=upExts;
  apiFetch('?action=admin_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json()}).then(function(d){if(d.ok){alert('✅ 设置已保存');location.reload()}else alert('保存失败')}).catch(function(){alert('保存失败，请重试')});
}
function submitComment(e, id){
  e.preventDefault();
  var btn=e.target.querySelector('button');
  const form = e.target;
  const data = Object.fromEntries(new FormData(form));
  if(btn)btn.disabled=true;
  apiFetch('?action=comment', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({post_id: id, ...data}) })
    .then(function(r){return r.json()})
    .then(function(d){ alert(d.msg || d.error || '提交失败'); if(d.ok) form.reset(); })
    .catch(function(){ alert('提交失败，请重试'); })
    .then(function(){ if(btn)btn.disabled=false; });
  return false;
}
(function(){
  var params=new URLSearchParams(location.search);
  var admin=params.get('admin');
  if(admin)loadAdminPage(admin);
})();
function updateReadingBar(){var el=document.getElementById('readingBar');if(!el)return;var h=document.documentElement.scrollHeight-window.innerHeight;el.style.width=(h>0?Math.min(100,window.scrollY/h*100):0)+'%';}
window.addEventListener('scroll',updateReadingBar,{passive:true});updateReadingBar();
function buildToc(){
  var box=document.getElementById('tocBox');if(!box)return;
  var hs=document.querySelectorAll('.single-post .content h1,.single-post .content h2,.single-post .content h3');
  if(!hs.length)return;
var html='<strong style="font-weight:700;display:block;margin-bottom:6px"><?=ico('list',15)?> 目录</strong>';
  hs.forEach(function(h){
    var id=h.textContent.trim().toLowerCase().replace(/[^\w\u4e00-\u9fa5]+/g,'-').replace(/^-+|-+$/g,'')||('h'+Math.random().toString(36).slice(2,7));
    h.id=id;
    var lv=h.tagName==='H2'?'l2':(h.tagName==='H3'?'l3':'l1');
    html+='<a class="'+lv+'" href="#'+id+'">'+escapeHtml(h.textContent.trim())+'</a>';
  });
  box.innerHTML=html;box.classList.add('show');
}
function initHighlight(){
  if(window.hljs){document.querySelectorAll('.single-post .content pre code:not(.hljs)').forEach(function(el){try{if(!el.className||el.className.indexOf('language-')<0)el.className='language-bash';hljs.highlightElement(el);}catch(e){}});}
}
function initMermaid(){
  if(!window.mermaid)return;
  document.querySelectorAll('pre code.language-mermaid').forEach(function(el){
    var div=document.createElement('div');div.className='mermaid';div.textContent=el.innerText||el.textContent;
    var pre=el.parentNode;if(pre&&pre.parentNode)pre.parentNode.replaceChild(div,pre);
  });
  if(window.mermaid){try{mermaid.initialize({startOnLoad:false});mermaid.run({nodes:document.querySelectorAll('.mermaid')});}catch(e){}}
}
function ensureMermaid(){
  if(window.mermaid)return;
  if(document.getElementById('mermaidLazy'))return;
  var s=document.createElement('script');s.id='mermaidLazy';s.src='editormd/lib/mermaid.min.js';
  s.onload=function(){try{initMermaid();}catch(e){}};
  document.head.appendChild(s);
}
function renderInlineStyles(){
  var root=document.querySelector('.single-post .content');if(!root)return;
  var h=root.innerHTML,n=renderInlineMarkers(h);
  if(n!==h)root.innerHTML=n;
}
function renderPreviewStyles(){
  var pv=document.querySelector('.editormd-preview-container');if(!pv)return;
  var h=pv.innerHTML,n=renderInlineMarkers(h);
  if(n!==h)pv.innerHTML=n;
}
function renderInlineMarkers(h){
  var blocks=[];
  h=h.replace(/<pre[\s\S]*?<\/pre>/g,function(m){blocks.push(m);return '\u0000B'+(blocks.length-1)+'\u0000';});
  h=h.replace(/\{font:(\d+)\}/g,'<span style="font-size:$1px">')
    .replace(/\{\/font\}/g,'</span>')
    .replace(/\{color:(#[0-9a-fA-F]{3,8})\}/g,'<span style="color:$1">')
    .replace(/\{\/color\}/g,'</span>')
    .replace(/\{tag:([a-zA-Z0-9]+)\}/g,function(m,t){return _tagWhitelist.indexOf(t)>-1?'<'+t+'>':'';})
    .replace(/\{\/tag:([a-zA-Z0-9]+)\}/g,function(m,t){return _tagWhitelist.indexOf(t)>-1?'</'+t+'>':'';});
  blocks.forEach(function(b,i){h=h.replace('\u0000B'+i+'\u0000',b);});
  return h;
}
function initPostExtras(){buildToc();initHighlight();initMermaid();renderInlineStyles();}
if(document.querySelector('.single-post')){initPostExtras();}
if(window.MutationObserver){var _pv=document.querySelector('.editormd-preview-container');if(_pv){new MutationObserver(function(){initMermaid();renderPreviewStyles();}).observe(_pv,{childList:true,subtree:true});}}
// 图片灯箱
var lb=document.getElementById('lightbox');
if(!lb){lb=document.createElement('div');lb.id='lightbox';document.body.appendChild(lb);}
lb.onclick=function(){lb.classList.remove('show');lb.innerHTML='';};
document.addEventListener('click',function(e){var t=e.target;if(t&&t.tagName==='IMG'&&t.closest('.single-post .content')){lb.innerHTML='<img src="'+t.src+'" alt="">';lb.classList.add('show');}});
// 目录滚动跟随
function tocScroll(){
  var box=document.getElementById('tocBox');if(!box)return;
  var links=box.querySelectorAll('a');var hs=document.querySelectorAll('.single-post .content h1,.single-post .content h2,.single-post .content h3');
  var cur=0;for(var i=0;i<hs.length;i++){if(hs[i].getBoundingClientRect().top<=140)cur=i;}
  links.forEach(function(a,j){a.classList.toggle('active',j===cur);});
}
if(document.querySelector('.single-post')){window.addEventListener('scroll',tocScroll,{passive:true});tocScroll();}
// PWA Service Worker
if('serviceWorker' in navigator){navigator.serviceWorker.getRegistrations().then(function(rs){rs.forEach(function(r){r.unregister().catch(function(){});});}).catch(function(){});}
syncThemeSelect();
</script>
<?php if(isAdmin()):?><script src="editormd/editormd.min.js?v=20260803"></script><?php endif;?>
</body></html>
