<?php
// dat→SQLite3+FTS5 取込（大容量対応）
ini_set('memory_limit','8G');
set_time_limit(0);

$use_default_tokenizer=!class_exists('MeCab_Tagger');

function tokenizeText(string $t):string{
    global $use_default_tokenizer;
    if($use_default_tokenizer){
        $p='/(?:\\p{Han}+|\\p{Hiragana}+|\\p{Katakana}+|[Ａ-Ｚａ-ｚ]+|[０-９]+|[A-Za-z]+|[0-9]+|[\\p{P}\\p{S}]+)/xu';
        return implode(' ',preg_split($p,$t,-1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE));
    }
    $tg=new MeCab_Tagger();
    $n=$tg->parseToNode($t);$o=[];
    for(; $n; $n=$n->getNext()){
        $s=$n->getStat();
        if($s===MeCab::MECAB_BOS_NODE||$s===MeCab::MECAB_EOS_NODE)continue;
        $o[]=$n->getSurface();
    }
    return implode(' ',$o);
}

function progress($d,$tot,$lab,&$t0,$w=30):void{
    if($d>$tot)return;
    if($t0===null)$t0=microtime(true);
    $p=$tot? $d/$tot:1;
    $bar=str_pad(str_repeat('=',(int)($p*$w)),$w,' ');
    $eta=$p?gmdate('H:i:s',(int)((1-$p)*(microtime(true)-$t0)/$p)):'--:--:--';
    printf("\r%-16s [%s] %3d%% %d/%d ETA:%s",$lab,$bar,$p*100,$d,$tot,$eta);
    if($d===$tot)echo " 完了\n";
    flush();
}

$db=new SQLite3(__DIR__.'/../bbs_log.db',SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
$db->busyTimeout(30000);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec('PRAGMA temp_store=MEMORY');
$db->exec('PRAGMA cache_size=-32768');

$db->exec("CREATE TABLE IF NOT EXISTS Boards(board_id TEXT PRIMARY KEY,board_name TEXT,category_id TEXT,category_name TEXT,thread_count INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS Threads(board_id TEXT,thread_id INTEGER,title TEXT,response_count INTEGER DEFAULT 0,PRIMARY KEY(board_id,thread_id))");
$db->exec("CREATE TABLE IF NOT EXISTS Posts(board_id TEXT,thread_id INTEGER,post_order INTEGER,name TEXT,mail TEXT,date TEXT,time TEXT,id TEXT,message TEXT,PRIMARY KEY(board_id,thread_id,post_order))");

$db->exec('DROP TABLE IF EXISTS Posts_fts');
$db->exec("CREATE VIRTUAL TABLE Posts_fts USING fts5(message,name,id,thread_title,content='Posts',content_rowid='rowid')");

$cfg=include __DIR__.'/board_config.php';
if(!is_array($cfg)){fwrite(STDERR,"board_config.php error\n");exit(1);}

$insB=$db->prepare("INSERT OR REPLACE INTO Boards VALUES(:bid,:bname,:cid,:cname,:cnt)");
$insT=$db->prepare("INSERT OR REPLACE INTO Threads VALUES(:bid,:tid,:title,:rc)");
$insP=$db->prepare("INSERT OR REPLACE INTO Posts VALUES(:bid,:tid,:ord,:name,:mail,:date,:time,:id,:msg)");

foreach(glob(__DIR__.'/categories/*',GLOB_ONLYDIR) as $cDir){
    $cid=basename($cDir);
    echo "\n[$cid]\n";
    foreach(glob("$cDir/*",GLOB_ONLYDIR) as $bDir){
        $bid=basename($bDir);
        if(in_array($bid,['dat','test'],true))continue;
        $catName=$cfg[$cid]['name']??$cid;
        $bName=$cfg[$cid]['boards'][$bid]??$bid;
        $dats=glob("$bDir/*.dat");
        $total=count($dats);
        if(!$total)continue;
        $insB->reset();
        $insB->bindValue(':bid',$bid);
        $insB->bindValue(':bname',$bName);
        $insB->bindValue(':cid',$cid);
        $insB->bindValue(':cname',$catName);
        $insB->bindValue(':cnt',0);
        $insB->execute();
        $done=0;$t0=null;
        foreach($dats as $file){
            $tid=(int)basename($file,'.dat');
            $db->exec('BEGIN IMMEDIATE');
            $post=1;$title='';
            $fp=fopen($file,'r');
            while(($l=fgets($fp))!==false){
                $l=mb_convert_encoding(rtrim($l),'UTF-8','SJIS');
                $p=explode('<>',$l);
                [$name,$mail,$dtid,$msg]=$p;
                if($post===1)$title=$p[4]??'';
                $date=$time=$id='';
                if(strpos($dtid,' ID:')!==false){[$dt,$id]=explode(' ID:',$dtid,2);}else{$dt=$dtid;}
                [$date,$time]=array_pad(explode(' ',$dt,2),2,'');
                $insP->reset();
                $insP->bindValue(':bid',$bid);
                $insP->bindValue(':tid',$tid);
                $insP->bindValue(':ord',$post);
                $insP->bindValue(':name',$name??'');
                $insP->bindValue(':mail',$mail??'');
                $insP->bindValue(':date',$date);
                $insP->bindValue(':time',$time);
                $insP->bindValue(':id',$id);
                $insP->bindValue(':msg',$msg??'');
                $insP->execute();
                $post++;
            }
            fclose($fp);
            $insT->reset();
            $insT->bindValue(':bid',$bid);
            $insT->bindValue(':tid',$tid);
            $insT->bindValue(':title',$title);
            $insT->bindValue(':rc',$post-1);
            $insT->execute();
            $db->exec('COMMIT');
            $done++;progress($done,$total,$bid,$t0);
        }
        $db->exec("UPDATE Boards SET thread_count=$total WHERE board_id='$bid'");
    }
}

echo "\nFTS building...\n";
$db->createFunction('tok','tokenizeText',1);
$db->exec('BEGIN IMMEDIATE');
$db->exec("INSERT INTO Posts_fts(rowid,message,name,id,thread_title)
           SELECT rowid,tok(message),tok(name),id,
                  tok((SELECT title FROM Threads WHERE Threads.board_id=Posts.board_id AND Threads.thread_id=Posts.thread_id))
           FROM Posts");
$db->exec('COMMIT');
$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$db->close();
 echo "done\n";
?>
