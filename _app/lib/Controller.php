<?php
/* @file routes.php 主路由
 * @package TCShare
 * @author xyToki
 */
namespace xyToki\xyShare;
use TC;
use Flight;
use Mimey\MimeTypes;
use flight\net\Route;
use xyToki\xyShare\Errors\NotFound;
use xyToki\xyShare\Errors\NotAuthorized;
class Controller{
    static function cachedUrl(){
        Flight::route("/_app/cached/@key",function($key){
            $res = Cache::getInstance()->getItem("tcshare_cached_.".$key);
            if ($res->isHit()) {
                return Flight::redirect($res->get(),302);
            }
        });
    }
    static function prepare($app,$base=""){
        global $TC;
        Flight::route($base."/*",function() use($app,$TC){
            Flight::response()->header("X-Powered-by","TCShare@xyToki");
            Flight::response()->header("X-TCshare-version",TC_VERSION);
            global $RUN;
            $RUN['app']=$app;
            $hasKey=false;
            foreach($TC['Keys'] as $k){
                if($k['ID']==$app['key']){
                    $RUN=array_merge($RUN,$k);
                    $hasKey=true;
                    break;
                }
            }
            if(!$hasKey){
                throw new Error("请正确配置Key");
            }
            $urlbase=Flight::request()->base;
            if($urlbase=="/")$urlbase="";
            $RUN=array_merge($RUN,$k);
            $RUN['URLBASE']=$urlbase;
            if(!isset($RUN['provider'])||empty($RUN['provider'])){
                $RUN['provider']="xyToki\\xyShare\\Providers\\ctyun";
            }else if(!empty($RUN['provider'])&&!strstr($RUN['provider'],"\\")){
                $RUN['provider']="xyToki\\xyShare\\Providers\\".$RUN['provider'];
            }
            return true;
        });
    }
    static function rules($rules,$splat,$fileInfo){
        $rs=[];
        foreach($rules as $rule){
            $rs[]=$rule;
        }
        for($i=0;$i<count($rs);){
            $ret=self::rule($rs[$i],"/".$splat,$fileInfo);
            if($ret==XS_RULE_HALT){
                return false;
            }
            if($ret==XS_RULE_SKIP){
                break;
            }
            $i+=$ret;
        }
        return true;
    }
    static function rule($rule,$path,$fileInfo){
        $thisType = $fileInfo->isFolder()?"folder":"file";
        if(isset($rule['ignore'])){
            $ignores = explode(";",$rule['ignore']);
            if( in_array($thisType,$ignores) || 
                ( !$fileInfo->isFolder()&&in_array($fileInfo->extension(),$ignores) )
            ){
                return XS_RULE_PASS;
            }
        }
        $pattern = $rule['route'];
        $encoded = TC::encodeURI($rule['route']);
        if(self::urlMatch($pattern)||self::urlMatch($encoded)){
            $type=$rule['type'];
            if(!$type)return XS_RULE_PASS;
            if(!empty($type)&&!strstr($type,"\\")){
                $type="xyToki\\xyShare\\Rules\\".$type;
            }
            return $type::check($path,$rule,$fileInfo);
        }
        return XS_RULE_PASS;
    }
    static function urlMatch($pattern){
        $url = $pattern;
        $methods = array('*');
        if (strpos($pattern, ' ') !== false) {
            list($method, $url) = explode(' ', trim($pattern), 2);
            $methods = explode('|', $method);
        }
        $route = new Route($url, false, $methods, false);
        $request = Flight::request();
        if ($route !== false && $route->matchMethod($request->method) && $route->matchUrl($request->url, false)) {
            return true;
        }
        return false;
    }
    static function installer($base=""){
        Flight::route($base."/-authurl",function(){
            global $RUN;
            if(!isset($RUN['provider'])||!class_exists($RUN['provider'])){
                throw new Error("Undefined provider >".$RUN['provider']."<");
            }
            $authProvider=isset($RUN['authProvider'])?$RUN['authProvider']:($RUN['provider']."Auth");
            $oauthClient=new Provider($authProvider,$RUN);
            Flight::json(["url"=>$oauthClient->url($_GET['callback'])]);
        });
        $cb=function(){
            Flight::render("install/ready");
        };
        Flight::route($base."/-install",$cb);
        Flight::route($base."/-renew",$cb);
        /* 授权回调 */
        Flight::route($base."/-callback",function() use($base){
            global $RUN;
            if(!isset($RUN['provider'])||!class_exists($RUN['provider'])){
                throw new Error("Undefined provider >".$RUN['provider']."<");
            }
            $authProvider=isset($RUN['authProvider'])?$RUN['authProvider']:($RUN['provider']."Auth");
            $oauthClient=new Provider($authProvider,$RUN);
            $oauthClient->getToken();
            $res=Config::write("XS_KEY_".$RUN['ID']."_ACCESS_TOKEN",$oauthClient->token());
            if( isset($RUN['ACCESS_TOKEN']) && $RUN['ACCESS_TOKEN']!="" ){
                    ?>
                    <h1>xyShare Renew</h1>
                    Renew proceeded successfully.<br/>
                    <?php
                    if($oauthClient->needRenew()){ ?>
                        Please renew your token MAUNALLY again before <code><?php echo $oauthClient->expires();?></code><br/>
                    <?php
                    }
                return;
            }
            
            if($res){
                Flight::redirect($base."/");
                return;
            }
            ?>
            <h1>xyShare Install</h1>
            <?php if(defined('XY_USE_CONFPHP')){ ?>
                Please set <code>ACCESS_TOKEN</code> below in <code>config.php</code>
            <?php }else{ ?>
                Please set  <code><?php echo "XS_KEY_".$RUN['ID']."_ACCESS_TOKEN" ?></code> below in environment variables.<br>
            <?php } ?>
            <textarea style="width:100%"><?php echo($oauthClient->token());?></textarea>
            Please renew your token again before <code><?php echo $oauthClient->expires();?></code><br/>
            <?php
            
        });
    }
    static function disk($base=""){
        /* 主程序 */
        Flight::route($base."/*",function($route) use($base){
            global $RUN;
            //初始化sdk
            $RUN['BASE']=$RUN['app']['base'];
            try{
                $app=new Provider($RUN['provider'],$RUN);;
            }catch(NotAuthorized $e){
                return Flight::redirect($base."/-install");
            }
            //格式化path
            $path="/".rawurldecode(urldecode(str_replace("?".$_SERVER['QUERY_STRING'],"",$route->splat)));
            $path=str_replace("//","/",$path);
            //获取文件信息
            try{
                $fileInfo=$app->getFileInfo($path);
            }catch(NotFound $e){
                return true;
                //Go to next disk until really 404.
            }
            if(!$fileInfo)return;
            //访问规则
            global $TC;
            if(!Controller::rules($TC['Rules'],$route->splat,$fileInfo)){
                return;
            }
            //有md5的，都是文件，跳走
            if(!$fileInfo->isFolder()){
                //预览
                $config=TC::get_preview_ext();
                if(
                    ($_SERVER['REQUEST_METHOD']=="POST"||isset($_GET['TC_preview']))
                    &&(isset($config[$fileInfo->extension()]))
                ){
                        Flight::render(
                            $RUN['app']['theme']."/".$config[$fileInfo->extension()],
                            array_merge($RUN,["file"=>$fileInfo,"base"=>$base,"path"->$path])
                        );
                        return;
                }else{
                    //直接返回
                    if(isset($_GET['TC_direct'])){
                        $max = 1024*1024;
                        if(isset($_ENV['XY_MAX_DIRECT_SIZE'])&&is_numeric($_ENV['XY_MAX_DIRECT_SIZE'])){
                            $max = $_ENV['XY_MAX_DIRECT_SIZE']*1024*1024;
                        }
                        if($fileInfo->size()<$max){
                            //大小正常，尝试直接输出
                            $content = TC::getFileContent($fileInfo->url(),$base."/".$path);
                            $mimes = new MimeTypes;
                            $filemime=$mimes->getMimeType($fileInfo->extension());
                            if(!$filemime)$filemime = "text/plain; charset=UTF-8";
                            Flight::response()->header("Content-Type",$filemime);
                            echo $content;
                            return;
                        }
                    }
                    //下载
                    Flight::redirect(isset($_GET['TC_transcode'])?$fileInfo->preview():$fileInfo->url(),302);
                    return;
                }
            }
            /*/是文件夹且不以`/`结尾，跳转带上
            if(substr($path,-1)!="/"){
                $rpath = rawurldecode(urldecode(str_replace("?".$_SERVER['QUERY_STRING'],"",Flight::request()->url)));
                Flight::redirect($rpath."/?".$_SERVER['QUERY_STRING'],301);
                return;
            }*/
            //列目录
            if(isset($_GET['TC_zip'])&&method_exists($fileInfo,"zipDownload")){
                //打包压缩
                Flight::redirect($fileInfo->zipDownload(),302);
                return;
            }
            list($folders,$files)=$app->listFiles($fileInfo);
            //排序
                $s = isset($_GET['sort'])?$_GET['sort']:"name";
                $sortableF=["name","timeModified","timeCreated","size","ext"];
                $sortableD=["name","timeModified","timeCreated"];
                if(in_array($s,$sortableF)){
                    usort($files, function($a, $b) use($s) {
                        $x = $a->$s();
                        $y = $b->$s();
                        return is_numeric($x)?$x-$y:strcmp($x,$y);
                    });
                }
                if(in_array($s,$sortableD)){
                    usort($folders, function($a, $b) use($s) {
                        $x = $a->$s();
                        $y = $b->$s();
                        return is_numeric($x)?$x-$y:strcmp($x,$y);
                    });
                }
                $o="asc";
                if(isset($_GET['order'])&&$_GET['order']=="desc"){
                    $folders = array_reverse($folders);
                    $files = array_reverse($files);
                    $o="desc";
                }
            //渲染
            if(substr($path,-1)!="/")$path=$path."/";
            Flight::response()->header("X-TCShare-Type","List");
            Flight::render($RUN['app']['theme']."/list",array_merge($RUN,[
                "current"=>$fileInfo,
                "path"=>$path,
                "folders"=>$folders,
                "files"=>$files,
                "sort"=>$s,
                "order"=>$o
            ]));
        },true);
    }
}