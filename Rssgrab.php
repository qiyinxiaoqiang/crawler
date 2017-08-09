<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Entere\Utils\SequenceNumber; 
use App\Model\Api\Platform;
use App\Model\Publicm;
use App\Model\Api\Article;
use Pdp\PublicSuffixListManager;
use Pdp\Parser;  
use App\Model\Api\Publicgrab;
use App\Events\ArticleSpiderEvent;
class Rssgrab extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sr:rssgrab';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'rss抓取';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    { 
        $rss = @simplexml_load_file('http://www.ifanr.com/feed','SimpleXMLElement', LIBXML_NOCDATA) or die('not find http://www.ifanr.com/feed');   
        $weblinks = (string)$rss->channel->link;
        $pslManager = new PublicSuffixListManager();
        $parser = new Parser($pslManager->getList()); 
        $host = $parser->parseUrl($weblinks)->host->host;
        $webdate = strtotime((string)$rss->channel->lastBuildDate)*1000;
        $sourceid = Platform::select('pid')->where('host',$host)->first();
        $sourceid = isset($sourceid['pid'])?$sourceid['pid']:0; 
        if($sourceid == 0){die('not find platform');};
        static $i = 0;
        foreach($rss->channel->item as $key=>$value){ 
        	$link = (string)$value->link;
        	$title = (string)$value->title;
        	$linkcount = Article::where('weblink',$link)->count(); 
        	$titlecount = Article::where('title',$title)->count();
    		if($linkcount >0 || $titlecount >0){continue;}    
        	$arr = []; 
        	$a_id = intval(Publicm::autoId('rsaid'));//内容id 
	        $aid = SequenceNumber::generateNumber($a_id,$prefix='',$width=10);//根据自增id生成随机等宽id  
	        $arr['aid'] = intval($aid); 
        	$arr['title'] = $title;
        	$arr['weblink'] = $link;
        	$arr['category'] = (array)$value->category?(string)$value->category[0]:'';
        	$arr['tags'] = is_array((array)$value->category)?(array)$value->category:[];
        	$arr['summary'] = (string)$value->description?explode('<p>#',(string)$value->description)[0]:''; 
        	$arr['platform_id'] = $sourceid;  
        	$web_author = $value->children('dc', true); 
        	$arr['webauthor'] = (string)$web_author->creator;
        	$ns_content = $value->children('content', true);
    		$content = $ns_content->encoded;
        	$arr['webauthor_add_time'] = strtotime((string)$value->pubDate)*1000;
        	preg_match('/<iframe[^>]*\s+src="([^"]*)"[^>]*>/is', $content, $matched);
        	$arr['videos'] = isset($matched[1])?$matched[1]:'';
        	$arr['musics'] = ''; 
        	$datalist = Publicgrab::grabindex($content,$arr['category'],'ifanr'); 
        	if($datalist == -1 || $datalist == -2){
        		$dir = date('Y-m',time()).'/';
				$fileName = $dir.date('Y-m-d',time()).'.txt';
				if (!is_dir($dir)) \Storage::makeDirectory($dir, 0777); 
        		if($datalist == -1){
        			\Storage::disk('local')->append($fileName, $arr['weblink'].'----- image greater than or Be equal to 20');
//        			\Log::info($arr['weblink'].'---ifanr image greater than or Be equal to 20'); 
        		}else if($datalist ==-2){
        			\Storage::disk('local')->append($fileName, $arr['weblink'].'----- image upload UpYun fail');
//        			\Log::info($arr['weblink'].'---ifanr image upload UpYun fail');
        		} 
        		continue;
        	};
			$arr['template_style'] = $datalist['template_style'];
			$arr['images'] = $datalist['images'];
			$arr['covers'] = $datalist['covers'];
			$deltext = '<p>#欢迎关注爱范儿官方微信公众号：爱范儿（微信号：ifanr），更多精彩内容第一时间为您奉上。</p>';
		    $deltexts = explode($deltext,$datalist['contents']);
		    $contents = str_replace($deltext.$deltexts[1],'',$datalist['contents']);
			$arr['content'] =  $contents;
			$arr['word_count'] = strlen($contents); 
			$arr['add_time'] =Millisecond(time()); 
			$arr['is_del'] = 'n';
			$arr['topic'] = 'sr_article'; 
			\Event::fire(new ArticleSpiderEvent($arr)); 
//		    Article::create($arr); 
		    $i++;
        } 
 
        if($i > 0){ 
	        Platform::where('pid',$sourceid)->update(['update_time'=>$webdate]); 
	        echo 'update-';
        }
       	echo 'ok'; 
    }
}
