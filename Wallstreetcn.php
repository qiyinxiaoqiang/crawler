<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use QL\QueryList;
use DB;
use App\Jobs\Domain\Channel; 
use App\Jobs\Domain\Wall; 
use App\Model\Publicm;
use Entere\Utils\SequenceNumber;
use Pdp\PublicSuffixListManager;
use Pdp\Parser;  
use App\Model\article;
use App\Model\Website;
use App\Http\Controllers\Rs\ReadsmarthrjController;
use App\Events\ArticleSpiderEvent;
class Wallstreetcn extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sr:wallstreetcn';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '华尔街见闻抓去';

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
        /*
        * @param index  数据采集添加
        * 1、读取华尔街网站信息信息
        * 2、设置抓去规则，采集详情页、列表图片、标题信息
        * 3、判断数据之前是否插入，存在跳过
        * 4、通过url获取列表详情页面,进行匹匹配相关数据信息
        * 5、筛选过滤掉无效的列表详情信息
        */
          //1、获取queryList采集信息
        //curl进行网站数据获取
        $url = 'http://m.wallstreetcn.com/';       
//        $source= $this->https_request($url);  
        $htmls = new Wall(); 
       
        $page = $htmls->operation(html_entity_decode(html_entity_decode($url)));   //or slef::https_request($url);
        if(!empty($page['source'])){
            //$urls = parse_url(html_entity_decode(html_entity_decode($url)));//解析网站域名
            //转换模版的字符集
            $urls = $this->convToUtf8($page['source']);
            //列表页规则
            $rules = array(
                //采集class为two下面的超链接的链接
                'link' => array('.news-item a','href'),//获取网站url
                'title'=>array('.content','text'),//标题
                'covers'=>array('.link-wrapper img','src'),
            );
            
            //创建数组
            $data = QueryList::Query($urls,$rules)->getData(function($item){
                // 获取列表页url
                $item['weburl'] = $this->isUrl($item['link']);
                return $item;
            });
            $dir = date('Y-m',time()).'/';
            $fileName = $dir.date('Y-m-d',time()).'.txt';
            if (!is_dir($dir)) \Storage::makeDirectory($dir, 0777); 
            //获取当前url地址信息
            if($data != ''){
                foreach($data as $k=>$v){
                    //在插入时，详情页中的url进行判 断，是否存在
                    $urls = $v['link'];//获取当前被插入的文章详情页
                   
                    //判断数据是否存在，存在跳过不存在直接添加
                    if($v['weburl'] != ' '){
                        //获取每条详情信息
                        $res = $this->firstDetails($v);
                        if($res){
                            if(count($res['image']['urls']) >20){
                                \Storage::disk('local')->append($fileName,$res['weblink'].'----- image numbers >20');
                                continue;
                            }
                            //判断当前数据库是否存在
                            $is_existing = \DB::connection('mongodb')->table('article')->where('weblink','=',trim($res['weblink']))->count();
                            if($is_existing && $is_existing !=0){
//                                \Log::info($res['weblink'].'Data exists ');
                                continue;
                            }
                            $is_existing_title = \DB::connection('mongodb')->table('article')->where('title','=',trim($res['title']))->count();
                            if($is_existing_title && $is_existing_title !=0){
//                                  \Log::info($res['title'].' Data exists ');
                                  continue;
                            }
                            //以上符合执行队列
                            \Event::fire(new ArticleSpiderEvent($res)); //执行卡不卡消息队 列
                            //执行完成后修改时间
                                $re = Website::updateTime($res['add_time']);
                                if($re){
                                    echo ' update ok';
                                }else{
                                    \Storage::disk('local')->append($fileName, $res['weblink'].'----- is platform Update update_time failed5');
//                                    \Log::info($res['weblink'].'is platform Update update_time failed5 ');
                                    continue;
                                }
                        }else{
                             \Storage::disk('local')->append($fileName, 'm.wallstreetcn.com'.'----- Failed to obtain 95 lines of code for single data4');
//                            \Log::info('m.wallstreetcn.com Failed to obtain 95 lines of code for single data4');
                            continue; 
                        }
                    }else{
                        \Storage::disk('local')->append($fileName, $v['link'].'----- The URL in the details page does not exist3');
//                        \Log::info($v['link'].'The URL in the details page does not exist3');
                        continue;
                    }
                }
            }
        }else{
              echo 'The Url not here';
        }
    }
     /*
     * @param $url string 详情页url
     * 1、主要获取详情页作者、文章、摘要、来源等信息
     * 2、对原作者span 标签进行判断editor-name和source-name两种
     * 3、文章内容中的图片连接判替换拍云上返回图片的url地址
     * 4、获取列表页中的文章首图，存入拍云
     * 5、重新组装数组，进行添加
     */
    public function firstDetails($url)
    {
        $detais = new Wall(); 
        $page = $detais->operation(html_entity_decode(html_entity_decode($url['weburl'])));
        //判断当前原作者span标签class='editor-name;还是class='source-name'
        $editorTemp = $this->convToUtf8($page['source']);//转换字符集
        $editor = array(
            'webauthor'=>array('.editor-name','text'),//原作者       
        ); 
        $editors = QueryList::Query($editorTemp,$editor)->getData(function($item){
             return $item;
        });
        $temDetails = $this->convToUtf8($page['source']);//转换字符集
        if($editors){
            $rules = array(
                'title'=>array('#wx-share-title','text'),//获取详情页title信息                   
                'webauthor_add_time'=>array('.create-time','text'),
                'summary'=>array('#article-summary','text'),//文章摘要
                'webauthor'=>array('.editor-name','text','.source-name'),//原作者       
                'content'=>array('.article-content','html','-.hide-for-app'),//正文
            ); 
        }else{
            $rules = array(
                'title'=>array('#wx-share-title','text'),//获取详情页title信息                   
                'webauthor_add_time'=>array('.create-time','text'),
                'summary'=>array('#article-summary','text'),//文章摘要
                'webauthor'=>array('.source-name','text'),//原作者来源

                'content'=>array('.article-content','html','-.hide-for-app'),//正文
            ); 
        }
        $docker = QueryList::Query($temDetails,$rules)->getData(function($item){
                $lasttime = $this->dataTime($item['webauthor_add_time']);
                $item['webauthor_add_time'] =$lasttime*1000;//将时间戳转换13位
                $item['add_time'] = Millisecond(time());
                $item['word_count'] = strlen(str_replace(' ','',$item['content']));//文章总字数
                //生成唯一的aid
                $wxc_id = intval(Publicm::autoId('rsaid'));//内容id 
                $wxcid = intval(SequenceNumber::generateNumber($wxc_id,$prefix='',$width=10));//根据自增id生成随机等宽id 
                $item['aid'] = intval($wxcid);
                $item['category'] = '';
                $item['tags'] = []; 
                //是否删除
                $item['is_del'] = 'n';
                $item['content'] = $item['content'];
                //1、匹配文章内容中的图片连接
                $preq =parseImgs($item['content'],'all');
                $content = delstyle($item['content']);
                if($preq['exists'] != "n"){
                    //2、存在上传到云盘云中获取url地址
                    $contentImg = $this->YunImg($preq['url']);
                    //3、拼接数据
                    $item['content'] = replaceimg($contentImg['data']['pic'],$contentImg['data']['pics'],$content,count($contentImg['data']['pics']));
                    
                    $item['images']['urls'] = isset($contentImg['data']['pic'])?$contentImg['data']['pic']:[];
                    //获取image时不需要前缀,后者替换前者image前缀
                    $item['image'] = [
                        'urls'=>$contentImg['data']['pic']
                    ];
                }else{
                    $item['image'] = [
                        'urls'=>[]
                    ];
                }
                //判断文章中是大图还是没图
                $item['template_style'] =2;
                
                $item['videos'] = ''; 
                //文章音乐
                $item['musics'] = ''; 
              return $item;
        });
        //获取当前抓取的域名信息
        $webLinks = 'http://m.wallstreetcn.com/';
        $pslManager = new PublicSuffixListManager();
        $parser = new Parser($pslManager->getList()); 
        $host = $parser->parseUrl($webLinks)->host->host; 
        //获取当前平台信息
        $platform = \DB::connection('mongodb')->table('platform')->where('host',$host)->first();      
        //end
        $docker[0]['weblink'] = $url['link'];
        //来源于
        $docker[0]['platform_id'] = intval($platform['pid']);
        //列表图像,存入数据库时不增加前缀
        $imgPic = array();
        array_push($imgPic,$url['covers']);
        $coversPic = $this->YunImg($imgPic);
        if($coversPic['meta']['code'] == 200){
                $docker[0]['covers'] = [
                    'url'=> $coversPic['data']['pic'][0]['url'],
                    'width'=>$coversPic['data']['pic'][0]['width'],
                    'height'=>$coversPic['data']['pic'][0]['height'],
                    "is_grab_image" =>$coversPic['data']['pic'][0]['is_grab_image'],
                ];
        }else{
            $docker[0]['covers'] = [];
        }
        $arrInfo = $this->insertArr($docker);
        return $arrInfo; 
    }
     /*
     * 
     *   @param Array $arr 组装数组
     */
    public function insertArr($arr)
    {
        
        $params = array();
        foreach($arr as $v){
            $params['aid'] = $v['aid'] ? $v['aid'] :0;
            $params['title'] = $v['title'] ? $v['title'] : '';
            $params['weblink'] = $v['weblink'] ? $v['weblink'] : '';
            $params['category'] = $v['category'] ? $v['category'] : '新闻推荐栏目';
            $params['tags'] = $v['tags'] ? $v['tags'] : []; 
            $params['summary'] = $v['summary'] ? $v['summary'] : '';//文章摘要
            $params['platform_id'] = $v['platform_id'];
            $params['webauthor'] = $v['webauthor'] ? $v['webauthor'] : '';//原文作者 
            $params['webauthor_add_time'] = $v['webauthor_add_time'] ? $v['webauthor_add_time'] : Millisecond(time());//时间获取失败，采用当前时间
            $params['videos'] = $v['videos'] ? $v['tags'] : '';
            $params['musics']= $v['musics'] ? $v['musics'] : '';
            $params['image'] = $v['image'];
            $params['covers'] = $v['covers'] ? $v['covers'] : '';
            $params['content'] = $v['content'] ? $this->replace_diffNotes($v['content']) : '';
            $params['word_count'] = $v['word_count'] ? intval($v['word_count']) : 0;
            $params['add_time'] = $v['add_time'] ? $v['add_time'] : time();
            $params['is_del'] = $v['is_del'] ? $v['is_del'] : '';
            $params['topic'] = 'sr_article'; 
            $params['template_style'] = $v['template_style'];
        }
       return $params;
    }
    /*
     * 上传云盘云
     * @$img是当前匹配到的图片链接
     *   @param Array $img
     */
    public function YunImg($img)
    {
        if(is_array($img)){
            $headers = array("Accept" => "application/json"); 
            $data1 = \Unirest\Request::post("http://image5.blogchina.com/v5_image/public/index.php/upload", $headers, ['uc_image_token'=>'afjkegkgjsss348@4@#asefksjyusrfg','type'=>'read_smart','uid'=>1,'imgurl'=>$img]); 
            $imgUrls = $data1->raw_body; 
            $response = json_decode($imgUrls,true); 
            return $response;
        }else{
            return $img;
        }
    }
   
     /*
     * 年月日时分秒转换时间戳
     *   @param string $data 日期
     */
    public function dataTime($data)
    {
        if($data){
             $res = strtotime(str_replace(array("年","月",'日'),array("-","-",''),$data));
             return $res;
        }
    }
     /*
     * 网站编码转换
     */
    public function convToUtf8($str) 
    {  
        if( mb_detect_encoding($str,"UTF-8, ISO-8859-1, GBK")!="UTF-8" ) {//判断是否不是UTF-8编码，如果不是UTF-8编码，则转换为UTF-8编码  
            return  iconv("gbk","utf-8",$str);  
        } else {  
            return $str;  
        }  
    }  
    /*
     * 把pc替换成m.h4格式
     */
    public function isUrl($url)
    {;
        $afterUrlone = 'http://wallstreetcn.com';        
        $in=strstr($url,$afterUrlone);
        if($in){
            $res = str_replace($afterUrlone,"http://m.wallstreetcn.com",$url);
            return $res;
        }else{
            return ' ';
        }  
    }
    //处理13位时间戳

    public function getMillisecond() { 
            list($s1, $s2) = explode(' ', microtime());
    return intval($s1*1000) + $s2*1000;
    }
     //正则匹配掉文章中的注释符号
    public function replace_diffNotes($content)
    {
        $content = preg_replace('#<!--.*-->#' , '' , $content);
        return $content;
    }






	public function https_request($url,$data = null)
	{
		/**Unirest抓取***/
		$headers = array("Accept" => "application/html");
		$data = \Unirest\Request::get($url, $headers, []); 
		$output	= $data->raw_body; 
		/***curl抓取***/
		if(empty($output) || $output == null || $output == false || strlen($output) <100){ 
			$data = null;
	    	$curl = curl_init();
		    curl_setopt($curl, CURLOPT_URL, $url);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
		    if (!empty($data)){
		        curl_setopt($curl, CURLOPT_POST, 1);
		        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		    }
		    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		    $output = curl_exec($curl);
		    curl_close($curl);
			
			/***file_get_contents抓取***/
			if(empty($output) || $output == null || $output == false  || strlen($output) <100){
				$output = file_Get_contents($url); 
				if(empty($output) || $output == null || $output == false){
					$output = '';
				}
			}
	    } 
		$output['source'] = $output;
	    return $output;
	}
    
}
