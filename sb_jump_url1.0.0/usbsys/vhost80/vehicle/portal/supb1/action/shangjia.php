<?php
error_reporting ( E_ERROR );

include_once dirname(__FILE__).'/../../../system/class/tool.class.php';
include_once dirname(__FILE__).'/../../../system/class/mem.class.php';
include_once dirname(__FILE__).'/../../../system/class/net.class.php';
include_once dirname(__FILE__).'/../../../system/class/wft.class.php';
include_once dirname(__FILE__).'/../../../system/class/log.class.php';
include_once dirname(__FILE__).'/../../../system/vehicle/vehicle/tcConfig.php';
include_once dirname(__FILE__).'/../../../system/class/wxbuss.class.php';
include_once dirname(__FILE__).'/wxgh.php';
include_once dirname ( __FILE__ ) . '/debug.php';



entry();

function entry(){
	$act = $_REQUEST ['act'];
	if($act){
		$buss = new Buss();
		$buss->shangjia();
	}
}


class Buss {
	function shangjia() {
		include_once dirname(__FILE__).'/config.php';
		$remoteIp = getenv ( "REMOTE_ADDR" );
		$mac = Tool::getMac ( $remoteIp );
		$did = Tool::getId ( $remoteIp );
		$configfile = '../../../config.json';
		$mem = new Mem ();
		//当前上网状态
		$now_state = $mem->get ( $remoteIp . 'p_net_state' );
		if(empty($now_state)){
			$now_state = 0x00;
		}

		$now_wechat = $mem->get ( $remoteIp . 'now_wechat' );

		if($now_wechat){
			// ghid|openid|mpid
			$nowwechat = explode ( '|', $now_wechat );
			$openid = $nowwechat [1];
			$ghid = $nowwechat[0];
			//清除now_wechat，防止从微信入口进入重复开网络
			$mem->del ( $remoteIp . 'now_wechat' );
		}else{//从微信入口进入$now_wechat为null
			debug($remoteIp.' wx app => shangjia now_state='.$now_state);
			$this->doState ( $mem, $remoteIp, false, $now_state );
			exit;
		}

		// 查询是否关注
		$id = $nowwechat[2];
		if($id==-1){
			$wxnowstatus = $mem->get($remoteIp . 'isContact');
		}else{
			$wxnowstatus = $this->checksubcribe ($id,$openid,$ghid);
		}

		$err_count = $mem->get ( $remoteIp . 'fail_count' );
		if($err_count === false){
			$err_count = 0;
		}
		if ($wxnowstatus == 1 || $err_count >= 2) { // 已关注
			// 重置失败次数
			$mem->set ( $remoteIp . 'fail_count', 0 );
			// 处理上网状态
			$this->doState ( $mem, $remoteIp, $now_wechat, $now_state );
		} else {
			debug($remoteIp." subcribe fail .now_state=$now_state err_count=$err_count");
			$this->do_error($mem, $remoteIp, $now_state);
		}
	}
	
	
	private function do_error($mem,$remoteIp,$now_state){
		// 未关注，跳转失败页
		$timeout = $mem->get ( $remoteIp . 'timeout' );
		if($timeout){
			//add logs
			$this->wx_fail_log(16);
			debug($remoteIp." timeout faile");
		}else{
			//add logs
			$this->wx_fail_log(($now_state & 0x0F)+1);
		}
		$err_count = $mem->get ( $remoteIp . 'fail_count' );
		if($err_count === false){
			$err_count = 0;
		}
		$err_count ++; // 累积失败次数，大于三次直接当做成功处理
		$mem->set ( $remoteIp . 'fail_count' , $err_count );
		
		$config  = json_decode(file_get_contents(dirname(__FILE__).'/../../../config.json'),true);
		
		// 自动跳开关
		$auto = $config['wxsw1'];
		// 自动跳转时间
		$atime = $config['maxfwx'];
		//			$parameter = "ghname=".urldecode($ghname).'&auto='.$auto.'&atime='.$atime;
		$configs = array('ghname'=>urldecode($ghname),'auto'=>$auto,'atime'=>$atime);
		
		$parameter = http_build_query($configs);
		header ( "Location:http://172.16.1.1/vehicle/portal/supb1/wx_fail.html?netstate=".$now_state.'&'.$parameter );
	}
	
	// 跳转页面
	private function gowxsuccess($net,$ghname) {

		    $config  = json_decode(file_get_contents(dirname(__FILE__).'/../../../config.json'),true);

			// 自动跳开关
			$auto = $config['wxsw1'];
			// 自动跳转时间
			$atime = $config['maxfwx'];
//			$parameter = "ghname=".urldecode($ghname).'&auto='.$auto.'&atime='.$atime;
			$configs = array('ghname'=>urldecode($ghname),'auto'=>$auto,'atime'=>$atime);

			$parameter = http_build_query($configs);
			if ($net == 1) {
				header ( "Location:http://172.16.1.1/vehicle/portal/supb1/wx_sucs_1.html?".$parameter);
			} else if ($net == 2) {
				header ( "Location:http://172.16.1.1/vehicle/portal/supb1/wx_sucs_2.html?".$parameter);
			} else if ($net == 3) {
				header ( "Location:http://172.16.1.1/vehicle/portal/supb1/wx_sucs_3.html?".$parameter);
			}else if($net==4){
				header ( "Location:https://www.yirendai.com/LandingPage/wap/ty/?siteId=2602&source=1");
//				header ( "Location:http://172.16.1.1/vehicle/portal/supb1/wx_sucs_n1.html?".$parameter);
			}else if($net==5){
			    header ( "Location:https://www.yirendai.com/LandingPage/wap/ty/?siteId=2602&source=1");

//				header ( "Location:http://172.16.1.1/vehicle/portal/supb1/wx_sucs_n3.html?".$parameter);
			}
	}
	
	// 处理状态
	private function doState($mem, $remoteIp, $now_wechat, $now) {
		
		$timeout = $mem->get ( $remoteIp . 'timeout' );
		$mem->del ( $remoteIp . 'timeout' );
		//处理网络状态逻辑
		if (($now & 0x0f) == 0x00) { // 一道
			$this->do_1($mem, $remoteIp, $now_wechat,$timeout,$now);
		} else if (($now & 0x0f) == 0x01) {//二道
			$this->do_2($mem, $remoteIp, $now_wechat,$timeout,$now);
		} else if (($now & 0x0f) == 0x02) {//三道
			$this->do_3($mem, $remoteIp, $now_wechat,$timeout,$now);
		} else if (($now & 0x0f) >= 3) { // 大于等于三道

			$this->do_other($mem, $remoteIp, $now_wechat,$timeout,$now);

		}
	}
	
	//一道处理
	private function do_1($mem,$remoteIp,$now_wechat,$timeout,$now){
		// 一道未关注，从微信入口进入
		if(!$now_wechat){
			$this->do_error($mem, $remoteIp, 0);
			exit;
		}
		//add logs
		$this->wx_sucs_log(1);
		$wft_list =	getWftWxAndCache($remoteIp,2);
		if($wft_list){
			$tcid = getTc ( BID, 2 );
			$this->neton ( $tcid, $now_wechat [2] ); // 开通上网
			$mem->set ( $remoteIp . 'p_net_state', 0x01 | ($now & 0xf0) );
			$this->gowxsuccess ( 1 ,$wft_list[0]['name']);
		}else{
			$tcid = getTc ( BID, 3 );
			$this->netVip ( $tcid, $now_wechat [2] );//三道开通vip上网
			$mem->set ( $remoteIp . 'p_net_state', ($now & 0xf0) | 0x01 );
			$this->gowxsuccess ( 4 );
		
		}
	}
	
	//二道处理
	private function do_2($mem,$remoteIp,$now_wechat,$timeout,$now){
		
		$wft_list =	getWftWxAndCache($remoteIp,3);
		
		if(!$now_wechat){//从微信进来
			if($wft_list){
				// 跳转一道成功页,
				$this->gowxsuccess ( 1 ,$wft_list[0]['name']);
			}else{
				//add logs
				$mem->set ( $remoteIp . 'p_net_state', ($now & 0xf0) | 0x01 );
				// 跳转三道成功页
				$this->gowxsuccess ( 4 );
			}
			exit;
		}
		
		if($wft_list){
			if ($timeout) { // 时长耗尽，p_net_state不用设置
				//add logs timeout
				$this->wx_sucs_log(16);
					
				$tcid = getTc ( BID, 2 );
				$this->neton ( $tcid, $now_wechat [2] ); //
				// 跳转一道成功页,
				$this->gowxsuccess ( 1 ,$wft_list[0]['name']);
			} else { // 二道成功
				//add logs
				$this->wx_sucs_log(2);
					
				$tcid = getTc ( BID, 3 );
				$this->neton ( $tcid, $now_wechat [2] ); // 开通上网
				$mem->set ( $remoteIp . 'p_net_state', ($now & 0xf0) | 0x02 );
				// 跳转二道成功页
				$this->gowxsuccess ( 2 ,$wft_list[0]['name']);
			}
		}else{
			//add logs
			$this->wx_sucs_log(2);
		
			$tcid = getTc ( BID, 3 );
			$this->netVip ( $tcid, $now_wechat [2] );
			$mem->set ( $remoteIp . 'p_net_state', ($now & 0xf0) | 0x02 );
			// 跳转三道成功页
			$this->gowxsuccess ( 5 );
		}
	}
	
	//三道处理
	private function do_3($mem,$remoteIp,$now_wechat,$timeout,$now){
		$wft_list =	getWftWxAndCache($remoteIp,3);
		
		if(!$now_wechat){//从微信进来
			if($wft_list){
				// 跳转二道成功页
				$this->gowxsuccess ( 2 ,$wft_list[0]['name']);
			}else{
				$mem->set ( $remoteIp . 'p_net_state', ($now & 0xf0) | 0x02 );
				// 跳转三道成功页
				$this->gowxsuccess ( 5 );
			}
			exit;
		}
		
		if($wft_list && $timeout){
			//add logs
			$this->wx_sucs_log(16);
			$tcid = getTc ( BID, 2 );
			$this->neton ( $tcid, $now_wechat [2] );
			// 跳转二道成功页
			$this->gowxsuccess ( 2 ,$wft_list[0]['name']);
		}else { // 三道成功
			//add logs
			$this->wx_sucs_log(3);
			$tcid = getTc ( BID, 3 );
			$this->netVip ( $tcid, $now_wechat [2] );
			$mem->set ( $remoteIp . 'p_net_state', ($now & 0xf0) | 0x03 );
			// 跳转三道成功页
			$this->gowxsuccess ( 3 );
		}
	}
	
	//大于三道的处理

	private function do_other($mem,$remoteIp,$now_wechat,$timeout,$now){
		//add logs
		if($now_wechat){
			if($timeout)
				$this->wx_sucs_log(16);
			else 
				$this->wx_sucs_log(($now & 0x0f)+1);
				
			$tcid = getTc ( BID, 3 );
			$this->netVip ( $tcid, $now_wechat [2] );
		}
		// 跳转三道成功页
		$this->gowxsuccess ( 3 );
	}
	
	// 开通上网
	private function neton($tc, $mpid) {
		$ip = getenv ( "REMOTE_ADDR" );
		Net::auth_wechat ( $ip, BID, $tc, $mpid );
	}
	/**
	 * 三道开通上网
	 * @param unknown $tc
	 * @param unknown $mpid
	 */
	private function netVip($tc, $mpid){
		$ip = getenv ( "REMOTE_ADDR" );
		Net::auth_wechat($ip, BID, $tc);//2017-1-4修改vip上网为普通上网
	}


	// 查询关注


	private  function  checksubcribe($id,$openid,$ghid){

		$isContact = 0;
		$remoteIp = getenv("REMOTE_ADDR");
		$mem = new Mem();
		$wxbuss = new wxbuss();
		if ($mem->has($remoteIp.'isContact')){
			$isContact = $mem->get($remoteIp . 'isContact');
			$mem->del($remoteIp . 'isContact');
		}
		$wxadd = $mem->get ( $remoteIp . 'add_wechat' );
		$tmp = explode ( ',', $wxadd );
		if($ghid!=''&&in_array($ghid,$tmp))return 1;


		 // 这里必须穿now_wchat以为上面mem中已经删掉,
		$state  = $wxbuss->check_wxattention($id,$openid,$isContact);
		if($state == 1){
			$mem->set ($remoteIp . 'add_wechat' ,$wxadd.','.$id);
			return 1;
		}
		return (0|$isContact);
	}
	//记录微信成功页日志
	private function wx_sucs_log($index){
		$os = getos();
		Logs::portal('wx_sucs','',$index,$os);
	}
	
	//记录微信失败日志
	private function wx_fail_log($index){
		$os = getos();
		Logs::portal('wx_fail','',$index,$os);
	}
}