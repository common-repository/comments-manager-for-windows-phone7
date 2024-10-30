<?php
/*
   Plugin Name: WP Comments Manager
   Plugin URI: http://rlbyte.com
   Description: Manage your blog comments directly from you Windows Phone 7 (Mango) device. Send tile updates and toast notifications to your WP7 device on new comments. (you also need to install comments manager for WP7, search for it on marketplace)
   Author: rlByte Software (rlbyte.com)
   Author URI: http://rlbyte.com
   Version: 1.0.0
  */

/*
   * Copyright (c) 2011-2012 rlByte Software
   * http://www.rlbyte.com/
   *
   * Permission is hereby granted, free of charge, to any person obtaining a copy
   * of this software and associated documentation files (the "Software"), to deal
   * in the Software without restriction, including without limitation the rights
   * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
   * copies of the Software, and to permit persons to whom the Software is
   * furnished to do so, subject to the following conditions:
   *
   * The above copyright notice and this permission notice shall be included in
   * all copies or substantial portions of the Software.
   *
   * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
   * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
   * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
   * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
   * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
   * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
   * SOFTWARE.
   */
     
class RazWPPush{
  protected 
    $plugin_version = 1,
    $wpdb,
    $db_name,
    $logged_in_user,
    $APP_POST="";
  function __construct(){
    global $wpdb;
    $this->user_settings = (array) get_option( 'razwpp_settings' );
    $this->wpdb = $wpdb;
    $this->db_name = $this->wpdb->prefix.'raz_wppush';
    $this->APP_POST=$_POST['razwppcmtpost'];
    
    //add_action("parse_request", array(&$this, "process_request"));
    //add_filter("query_vars", array(&$this, "add_wg2_query_vars"));
    add_action("parse_request", array(&$this, "checkForRequests"));
    add_filter("query_vars", array(&$this, "add_razwpp_query_vars"));
    //register_activation_hook( __FILE__, array(  &$this, 'install_plug' ) );
    add_action('admin_notices', array(&$this, "admin_notice"));

    
    if( is_admin() ){
      //$this->maybe_install();
      add_action( 'admin_init', array( &$this, 'admin_init' ) );
      add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
      add_filter( 'plugin_action_links', array(&$this,'set_plugin_action_links'), 10, 2 );
    }else {
        $reg_device=get_option('razwpp_device');
        if (empty($reg_device)) $reg_device=FALSE; 
       
       if ($reg_device) //hook on insert comment
        add_action('comment_post', array( &$this,'on_wp_insert_comment'));
        
        
      //add_action( 'the_content', array( &$this, 'replace_frontend_links' ), 1 );
    }

  } // __construct

function admin_notice()
{
    global $wp_version;
    if ( $wp_version < 2.9 ) 
      $this->ShowAdminMessage("<a href='options-general.php?page=comments-manager-for-windows-phone7/wp-comments-push.php'>WP7 Comments Manager plugin</a> requires at least v<strong>2.9</strong> of WordPress. Please Upgrade Your WordPress Installation.",true);
      
    if ($_GET['page']=="comments-manager-for-windows-phone7/wp-comments-push.php" || $_GET['activate']=="true")
    {  
        if (!$this->_iscurlinstalled())              
             $this->ShowAdminMessage("<a href='http://www.php.net/manual/en/book.curl.php'>cURL for PHP</a> is required by <a href='options-general.php?page=comments-manager-for-windows-phone7/wp-comments-push.php'>WP7 Comments Manager plugin</a>. Comments Manager will not work for you, sorry :-( ",false);
             
        if (!function_exists("mcrypt_module_open"))
             $this->ShowAdminMessage("<a href='http://www.php.net/manual/en/book.mcrypt.php'>Mcrypt for PHP</a> is required by <a href='options-general.php?page=comments-manager-for-windows-phone7/wp-comments-push.php'>WP7 Comments Manager plugin</a>. Encrypt Login feature will not work, make sure you turn it off in your phone app.",true);
    }
    if (!function_exists("json_encode"))              
          $this->ShowAdminMessage("<a href='http://www.php.net/manual/en/ref.json.php'>JSON for PHP</a> is required by <a href='options-general.php?page=comments-manager-for-windows-phone7/wp-comments-push.php'>WP7 Comments Manager plugin</a>. It will not work for you without it, sorry :-(",true);
              
}

function ShowAdminMessage($message, $errormsg = false)
{
	if ($errormsg) {
		echo '<div id="message" class="error">';
	}
	else {
		echo '<div id="message" class="updated fade">';
	}
	echo "<p><strong>$message</strong></p></div>";
}    


function add_razwpp_query_vars ($qvars)
{
  $qvars[] = 'razwppcmtpost';
  /*$qvars[] = "razwpp_register";
  $qvars[] = "razwpp_act";
  $qvars[] = "razwpp_user";
  $qvars[] = "razwpp_pass";
  $qvars[] = "razwpp_device";
  $qvars[] = "razwpp_channel";*/
  return $qvars; 
}


function checkForRequests($rwqvars)
{       
    if( isset( $_GET['razwpp_register']) || isset($_GET['razwpp_act']) )
      {
        if (empty($_GET['razwpp_user']) || empty($_GET['razwpp_pass']))
         die ("Invalid request!");
        
        global $wp_version;
        if ( $wp_version < 2.9 ) 
         die("\nPlugin Mode requires at least WordPress v2.9 . Please Upgrade Your WordPress Installation on ".site_url()." or switch off Plugin Mode from Settings->Connection.");
           
        require_once(ABSPATH . WPINC . '/registration.php');
        $username =""; $password ="";
        if (intval($_GET['razwpp_encr'])==1) //user/pass are encrypted?
         {
             if (!function_exists("mcrypt_module_open"))  //just in case
                  die("\nPlease disable Encrypt Login from Settings->Connection and try again. It looks like your server (".site_url().") does not have Mcrypt extension (libmcrypt), which is required for this feature.");                    
                   
           $username=$this->DecryptData($_GET['razwpp_user']);
           $password=$this->DecryptData($_GET['razwpp_pass']);            
         }
        else
        {
           $username =$_GET['razwpp_user'];
           $password =$_GET['razwpp_pass'];
        } 
         
        $device=$_GET['razwpp_device'];
        $channel=$_GET['razwpp_channel'];

        
        //check if user name exists
        $usid= username_exists( $username );
        if ($usid)
            { 
                $this->logged_in_user = get_userdata( $usid );
                $result = wp_check_password($password, $this->logged_in_user->user_pass,$this->logged_in_user->ID); //check if user/pass match
                if ($result===TRUE)
                 {
                   if ($this->logged_in_user->user_level==10)
                   {
                       if ($_GET['razwpp_act']==1) //do comments actions like get, delete, aprove,reply etc   
                         {
                            wp_set_current_user($usid); //don't do this and wp_new_comment will fail
                            $post_data=$this->APP_POST;
                            //die("razwppcmtpost".$this->APP_POST);
                            if (empty($post_data)) //just in case
                            {                                                                                    
                                if (array_key_exists ('razwppcmtpost',$rwqvars->query_vars))
                                   $post_data = $rwqvars->query_vars['razwppcmtpost'];
                                else //check for direct post if above fails?
                                   $post_data=$_POST['razwppcmtpost'];
                            }  
                               
                           $this->DoCommentAction($post_data);
                           die ("");
                         }
                        
                        if (empty($device) || empty($channel))
                         die ("Invalid request!");
                          
                        update_option('razwpp_device', $device );
                        update_option('razwpp_channel',$channel);  
                        $options = get_option('razwpp_settings'); 
                        
                        $options['toast']=intval($_GET['toast'])==0 ? '':'1';
                        $options['tile']=intval($_GET['tile']) ==0 ? '':'1'; 
                        update_option('razwpp_settings',$options);                             
                        die ("OK");
                  }
                  else
                    die ("The permissions granted to user '$username' are insufficient for performing this operation. You need admin rights (level 10)."); 
                }
                else             
                  die ("Username/password mismatch!");                 
            }           
       else
            die ("Username/password mismatch!"); 
      }
     
    
}


function get_current_user_id()
{
  if(!is_user_logged_in()) return 0; 
  global $current_user;
  get_currentuserinfo();  
  return $current_user->ID;  
}

function DoCommentAction($post_data)
{
    
      if (!empty($_GET['razwpp_gcp']))
       $_GET['razwpp_gcp']=trim($_GET['razwpp_gcp'],",");
       
                    if ($_GET['razwpp_gc']=="1") //get comments
                     {
                        $back_agent=intval($_GET['backagent']); //if request is done from background agent we only send back the id, type and date of comment to reduce data usage
                        $option_name='razwpp_comm_cnt';
                        if ( get_option( $option_name ) != "0" ) 
                            update_option( $option_name, "0" );
                        else
                            add_option( $option_name, "0", ' ', 'no' );

                        // update_option( 'razwpp_comm_cnt', '0' ); //reset comments counter for tile info
                         
                        
                        $bCms = array();
                        $c_post_id=-1;
                        $c_post_title=-1;
                        $tot_cmts_to_grab=intval($_GET['razwpp_max']);//50;
                        if ($tot_cmts_to_grab<1)
                         $tot_cmts_to_grab=20;
                         
                        //$comments_by_type = &separate_comments(get_comments('status=approve&post_id=' . $id));
                        for ($t=0;$t<4;$t++)
                        {
                           $type="";
                           if ($t==0) $type="approve";
                           else if ($t==1) $type="hold";
                           else if ($t==2) $type="spam";
                           else if ($t==3) $type="trash";
                           
                            $cmt_current_offset=0; //set offset
                            $args = array(  'status' => $type,'number' => $tot_cmts_to_grab,'offset'=>0);                            
                            $comments = get_comments($args);   
                            //die("cnt".count($comments));
                            //    die("hell"); 
                            $bCms[$type]=array(); 
                            $options = get_option('razwpp_settings'); 
                            //don't get my own comments?
                            $c_id=0;
                            if (intval($options['notmyowncomments'])==1 && !empty($options['myidonc']))
                                $c_id=intval($options['myidonc']);//intval(get_current_user_id());
                                
                             
                             $skip_it_is_closed_post =false; 
                             $tot_cmts=count($comments); 
                             $tot_cmts_stored=0; 
                             //foreach($comments as $comment)
                             for ($i=0;$i<$tot_cmts;$i++)
                             {
                                $comment=$comments[$i];  
                                //print_r($comment);

                                //skip my own comments  ?                                                               
                                if ($c_id==0 || $c_id != $comment->user_id) //if ($c_id!=0 && $c_id == $comment->user_id)
                                 {                                                                                                      
                                    if ($c_post_id!=$comment->comment_post_ID) //tiny optimization so we don't get the same post again
                                     { 
                                        $c_post_id=$comment->comment_post_ID;
                                        $post_idx = get_post($c_post_id); //get post title
                                        $c_post_title = $post_idx->post_title;
                                        
                                        if (intval($options['skipclosed'])==1 && !comments_open($comment->comment_post_ID))
                                         $skip_it_is_closed_post=true;
                                        else
                                         $skip_it_is_closed_post=false; 
                                      } 
                                        //skip closed posts
                                     if (!$skip_it_is_closed_post)
                                     {                                        
                                            $avatar="http://0.gravatar.com/avatar/ad516503a11cd5ca435acc9bb6523536?s=16";
                                            $name=$comment->comment_author;
                                            
                                            $name_d=$comment->comment_author;
                                            if (is_email($comment->comment_author_email))
                                             { 
                                                $hash = md5($comment->comment_author_email);
                	                            $avatar = 'http://www.gravatar.com/avatar/' . $hash . '?s=16&d=http://0.gravatar.com/avatar/ad516503a11cd5ca435acc9bb6523536?s=16';
                                                //$avatar=get_avatar( $comment->comment_author_email,16, 'http://0.gravatar.com/avatar/ad516503a11cd5ca435acc9bb6523536?s=16' );
                                                $name_d=$comment->comment_author . "(".$comment->comment_author_email.")";
                                             }
                                             
                                             
                                             //"1/13/2012 4:09:06 PM"
                                             if (strlen($name_d)>25)
                                              $name_d=substr($name_d,0,24)."...";
                                            //comment_post_ID 
                                            $data = array('comment'=> $back_agent==1? "": $comment->comment_content,  
                                                              'commentid' => $comment->comment_ID,  
                                                              'datetime' => $comment->comment_date_gmt,//mysql2date("n/j/Y g:i:s A",false),  
                                                              'image' => $back_agent==1? "": $avatar ,
                                                              'name'=> $back_agent==1? "": $name,
                                                              'name_d'=>$back_agent==1? "": $name_d,
                                                              'email'=>$back_agent==1? "": $comment->comment_author_email,
                                                              'url'=>$back_agent==1? "": $comment->comment_author_url,
                                                              'ip'=>$back_agent==1? "": $comment->comment_author_IP,
                                                              'status'=>$back_agent==1? "": $type,
                                                              'post_title'=>$back_agent==1? "": $c_post_title,
                                                              'comment_parent'=>$back_agent==1? "0": $comment->comment_parent,
                                                              'post_id'=>$back_agent==1? "0": $comment->comment_post_ID,
                                                              'type'=>$comment->comment_type
                                                              );                                                  
                                            $bCms[$type][]=$data;
                                            //$tot_cmts_stored++;
                                            //continue;
                                     } //skip on closed posts
                                     
                                     //continue;
                                   }//skip my own comments 
                               
                               if ($tot_cmts==$i+1) //end of loop?
                                 {
                                    $tot_cmts_grabbed = count($bCms[$type]);
                                    if ($tot_cmts_grabbed<$tot_cmts_to_grab) //get more comments if we are not in the limit
                                     {
                                          $cmt_current_offset +=$tot_cmts_to_grab+1;
                                          $args = array(  'status' => $type,'number' => $tot_cmts_to_grab,'offset'=>$cmt_current_offset);
                                          $comments = get_comments($args); 
                                          if (count($comments)>0) // no more comments?
                                          {                                                
                                              $tot_cmts=count($comments);
                                              $i=0;
                                          }
                                     }
                                    
                                 }
                                                                                                   	
                             }//for
                         }
                          // $j_comm[]=$bCms['approved']; 
                           
                          //if (count($j_comm)>0)
                              if (empty($bCms))                              
                                 die ("No comments found.");
                              echo "OK";   
                              echo (json_encode($bCms));                                                     
                          
                          
                                                          
                           die("");  
                     }  
                    else if ($_GET['razwpp_gc']=="2") //trash comments
                     {
                        $ids=explode(",", $_GET['razwpp_gcp']);
                        foreach ($ids as $c_id)
                         {
                            if (is_numeric($c_id))
                             wp_trash_comment ($c_id);
                            //wp_delete_comment( $c_id ); 
                         }
                         echo "OK";
                         $cnt=count($ids);
                         if ($cnt==1)
                          $info ="Comment successfully moved to trash.";
                         else
                           $info ="$cnt comments successfully moved to trash.";                                                    
                        die ($info);
                     } 
                    else if ($_GET['razwpp_gc']=="3") //spam comments
                     {
                        $ids=explode(",", $_GET['razwpp_gcp']);
                        foreach ($ids as $c_id)
                         {
                            if (is_numeric($c_id))
                             wp_spam_comment ($c_id);
                         }
                         echo "OK";
                         $cnt=count($ids);
                         if ($cnt==1)
                          $info ="Comment successfully marked as spam.";
                         else
                           $info ="$cnt comments successfully marked as spam.";                                                    
                        die ($info);
                     } 
                    else if ($_GET['razwpp_gc']=="4") //approve comments
                     {
                        $ids=explode(",", $_GET['razwpp_gcp']);
                        foreach ($ids as $c_id)
                         {
                            if (is_numeric($c_id))
                             wp_set_comment_status( $c_id, 'approve' );
                         }
                         echo "OK";
                         $cnt=count($ids);
                         if ($cnt==1)
                          $info ="Comment successfully approved.";
                         else
                           $info ="$cnt comments successfully approved.";                                                    
                        die ($info);
                     }
                    else if ($_GET['razwpp_gc']=="5") //unapprove comments
                     {
                        $ids=explode(",", $_GET['razwpp_gcp']);
                        foreach ($ids as $c_id)
                         {
                            if (is_numeric($c_id))
                            wp_set_comment_status( $c_id, 'hold' );
                         }
                         echo "OK";
                         $cnt=count($ids);
                         if ($cnt==1)
                          $info ="Comment successfully unapproved.";
                         else
                           $info ="$cnt comments successfully unapproved.";                                                    
                        die ($info);
                     }     
                    else if ($_GET['razwpp_gc']=="6") //delete permanently  comment(s)
                     {
                        $ids=explode(",", $_GET['razwpp_gcp']);
                        foreach ($ids as $c_id)
                         {
                            if (is_numeric($c_id))
                              wp_delete_comment( $c_id ); //wp_trash_comment ($c_id);
                             
                         }
                         echo "OK";
                         $cnt=count($ids);
                         if ($cnt==1)
                          $info ="Comment successfully deleted.";
                         else
                           $info ="$cnt comments successfully deleted.";                                                    
                        die ($info);
                     }   
                    else if ($_GET['razwpp_gc']=="7") //edit comment
                     {
                        if (empty($post_data))
                         die("No comment data received."); //just in case
                         
                        global $wpdb;
                        $cid=intval($_GET['razwpp_gcp']);
                        $json=stripslashes($post_data);
                        $cm=json_decode($json);
                        if (function_exists("json_last_error"))
                         {
                             switch(json_last_error())
                                    {
                                        case JSON_ERROR_DEPTH:
                                            $error =  ' - Maximum stack depth exceeded';
                                            break;
                                        case JSON_ERROR_CTRL_CHAR:
                                            $error = ' - Unexpected control character found';
                                            break;
                                        case JSON_ERROR_SYNTAX:
                                            $error = ' - Syntax error, malformed JSON';
                                            break;
                                        case JSON_ERROR_NONE:
                                        default:
                                            $error = '';                   
                                    }
                               if (!empty($error))
                                die ($error."\n".$json);
                          }
                          else if ($cm==null)
                            die ("Syntax error, malformed JSON");
                            
                             $info=$wpdb->get_row($wpdb->prepare("SELECT comment_post_ID FROM $wpdb->comments WHERE comment_ID = %d LIMIT 1", $cid), OBJECT);
                             if (empty($info)) die ("Can't find comment id ".$cid);
                                     
                             $content = apply_filters('comment_save_pre', $cm->comment); // escaping, same as wp-admin comment editing
                             //print_r($content);
                             //die ($cm->name.$cm->email.$cm->url.$cid.$post_data);
                             //update comment in db
		                     $wpdb->query( $wpdb->prepare("UPDATE $wpdb->comments SET comment_content = '$content', comment_author=%s, comment_author_email=%s, comment_author_url=%s  WHERE comment_ID = %d",$cm->name,$cm->email,$cm->url, $cid) );
                              
                         die("OKComment successfully saved.");                               
                     }
                    else if ($_GET['razwpp_gc']=="8") //reply to comment
                    {
                        if (empty($post_data))
                         die("No comment data received."); //just in case
                         
                        global $wpdb;
                        $pid=intval($_GET['razwpp_gcp']); //id of parent comment
                        $cm=$this->UnJson($post_data);
                        if (!is_object($cm))
                         die ("Failed to decode JSON");                                                           
                             
                        $comment['comment_post_ID'] = intval($cm->post_id);
                        $comment['comment_author'] =$wpdb->escape($this->logged_in_user->display_name);
                        $comment['comment_author_email'] = $wpdb->escape( $this->logged_in_user->user_email );
		                $comment['comment_author_url'] = $wpdb->escape( $this->logged_in_user->user_url );
		                $comment['user_ID'] = $this->logged_in_user->ID;      
                        $comment['comment_parent'] =intval($cm->parent);
	                    $comment['comment_content'] =$wpdb->escape($cm->comment);                              
                        
                        //duplicate check. Don't do this check and wp_new_comment will fail on dublicates
                    	$dupe = "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID = '{$comment['comment_post_ID']}' AND comment_approved != 'trash' AND ( comment_author = '{$this->logged_in_user->display_name}' ";
                    	if (!empty($comment['comment_author_email']))
                    		$dupe .= "OR comment_author_email = '{$comment['comment_author_email']}' ";
                    	$dupe .= ") AND comment_content = '{$comment['comment_content']}' LIMIT 1";
                    	if ( $wpdb->get_var($dupe) )
                    		die ("Duplicate comment detected. It looks as though you've already said that.");

                        wp_new_comment($comment); //if this fails will throw 404 (for ex if you don't set current user with wp_set_current_user)
                        die("OKYou have successfully replied to comment.");  
                    }                                                                                                        
                  else
                   die ("Invalid action request!");
                     
    
    
}


/**
 * @param $post_data json string to unpack
 * @return array with json. On fail will die with error description (if available)
 */
function UnJson($post_data)
{
    $json=stripslashes($post_data);
    $cm=json_decode($json);
    if (function_exists("json_last_error"))
     {
         switch(json_last_error())
                {
                    case JSON_ERROR_DEPTH:
                        $error =  ' - Maximum stack depth exceeded';
                        break;
                    case JSON_ERROR_CTRL_CHAR:
                        $error = ' - Unexpected control character found';
                        break;
                    case JSON_ERROR_SYNTAX:
                        $error = ' - Syntax error, malformed JSON';
                        break;
                    case JSON_ERROR_NONE:
                    default:
                        $error = '';                   
                }
           if (!empty($error))
            die ($error."\n".$json);
      }
      else if ($cm==null)
        die ("Syntax error, malformed JSON"); 
        
   return $cm;                            
}
            
function DecryptData($data)
{
    if (!function_exists("mcrypt_module_open")) return $data; //just in case
    
    $key =$this->hex2str("588945D6F47CAD7FDA70B5F40EC2AD07F4AB6BC8F2DAB962");    
	$iv    = "\0\0\0\0\0\0\0\0";
    $td = mcrypt_module_open (MCRYPT_3DES, "", MCRYPT_MODE_CBC, "");
    $key_add = 24-strlen($key);
    $key .= substr($key,0,$key_add);
    mcrypt_generic_init ($td, $key, $iv);
    $text = mdecrypt_generic ($td, $this->hex2str($data));
    mcrypt_generic_deinit($td);
    mcrypt_module_close($td);
    $block = mcrypt_get_block_size('tripledes', 'cbc');
    $packing = ord($text{strlen($text) - 1});
    if($packing and ($packing < $block))
    {
      for($P = strlen($text) - 1; $P >= strlen($text) - $packing; $P--)
       {
       if(ord($text{$P}) != $packing)
         $packing = 0;         
       }
    }
    $text = substr($text,0,strlen($text) - $packing);
   return $text;
}

function hex2str($hex)
{				
  $len = strlen($hex);
  $retval = '';
  for($i=0; $i < $len; $i+= 2) 
   $retval .= pack("C", hexdec(substr($hex, $i, 2)));
  return $retval;	
}

function on_wp_insert_comment( $id=0, $status=0)
{
    $reg_device=get_option('razwpp_device');
    if (empty($reg_device)) return $id; //no device yet?  
    $channel = get_option('razwpp_channel'); //the push channel
    if (empty($channel)) return $id; //no channel? or channel expired/disabled?
            	
    $opt = get_option('razwpp_settings'); 
    //don't push on my own comments?
    if (intval($opt['notmycomments'])==1 && !empty($opt['myid']))
    {
        $c_id=intval($this->get_current_user_id());
        if ($c_id!=0 && $c_id == intval($opt['myid']))
         return $id;        
    }
    
    global $wpdb;
    require_once ("wp7push.php");
    
            
    $info=$wpdb->get_row($wpdb->prepare("SELECT comment_post_ID, comment_author_email, comment_approved, comment_type, comment_content 
                                         FROM $wpdb->comments WHERE comment_ID = %d LIMIT 1", $id), OBJECT);
     if (empty($info)) return ($id);
             
    if (intval($opt['nospam'])==1 && $info->comment_approved == 'spam')
	 return $id; //no push on spam
    
    if (intval($opt['nopingback'])==1 && ($info->comment_type == 'trackback' || $info->comment_type == 'pingback')) 
     return $id; // no push on pinback or trackback
                     
    $comment = substr($info->comment_content ,0,40); //wp_filter_nohtml_kses( 
    
    //print_r($opt);
    //die ($op['tile'].$opt['toast']);
    $pushClient= new WindowsPhonePushClient($channel);
    $push_result[0]="n/a"; $push_result[1]="n/a";
    if (intval($opt['toast'])==1)
     {
        $push_result[0] = $pushClient->send_toast("New Comment:",strip_tags(wp_filter_nohtml_kses($comment)));
        
     }
    if (intval($opt['tile'])==1 && !$pushClient->channel_disabled )
    {
        
        //sending push: http://msdn.microsoft.com/en-us/library/hh202945%28v=vs.92%29.aspx
        $c_cnt = intval(get_option('razwpp_comm_cnt')); //counter of new comments since last app refresh
        $c_cnt++;
        update_option( 'razwpp_comm_cnt', "$c_cnt" );
        $tile_image=plugins_url( 'img/tilewp.png' , __FILE__ );
        $title_info="New Comment". ($c_cnt>1?"s":"");
        
        $push_result[1] = $pushClient->send_tile_update($tile_image,0,$title_info); //main tile update
        
        if (!$pushClient->channel_disabled) //just in case it gets disabled on main tile update
        {
            $s_url=urlencode(site_url());
            $tile_id="/MainPage.xaml?blog=".$s_url; //http%3a%2f%2fraz-soft.com
            $push_result[1] = $pushClient->send_tile_update($tile_image,$c_cnt,"",WindowsPhonePushPriority::TileImmediately,$tile_id);
        }
    }
    else
     update_option( 'razwpp_comm_cnt', "0" );
    
    
    if ($pushClient->channel_disabled)         
        update_option('razwpp_channel',""); //disable further notifications by removing channel
            
    //$status .= "<hr /><br />[--><u>Sent Toast to {$user['id']}  </u><--] <br /><br />$status";
    update_option( 'razwpp_laststatus', $push_result );
    update_option( 'razwpp_laststatus_info', $pushClient->response_info );
    
   return $id; 		     		    
}
  //not used, maybe in the feature...
  public function maybe_install(){
    if(@is_file(ABSPATH.'/wp-admin/upgrade-functions.php')) {
      include_once(ABSPATH.'/wp-admin/upgrade-functions.php');
    } elseif(@is_file(ABSPATH.'/wp-admin/includes/upgrade.php')) {
      include_once(ABSPATH.'/wp-admin/includes/upgrade.php');
    }
    $charset_collate = '';
    if($wpdb->supports_collation()) {
      if(!empty($wpdb->charset)) {
        $charset_collate = "DEFAULT CHARACTER SET {$this->wpdb->charset}";
      }
      if(!empty($wpdb->collate)) {
        $charset_collate .= " COLLATE {$this->wpdb->collate}";
      }
    }

    $db_schema = "CREATE TABLE {$this->db_name} (
      `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `deviceid` TEXT NOT NULL,
      `regdate` TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      `channelurl` TEXT NOT NULL,
      `hits` BIGINT(20) NOT NULL,
      INDEX ( `id` , `deviceid` )
    ) $charset_collate;";
    maybe_create_table($this->db_name, $db_schema);
    unset( $db_schema );
    update_option( 'raz_wppush_version', $this->plugin_version );
  } // maybe_install


    public function admin_menu()
      {
        add_action('admin_print_styles',array(&$this,'add_enqueue_style'));
        add_options_page( 'WP Comments Manager', '<span id="razwpp_sidebar_icon"></span>WP Comments Manager', 'manage_options', __FILE__, array( &$this, 'admin_page' ) );
      } // admin_menu
    
    public static function add_enqueue_style()
    {
        $razwppdata = get_plugin_data(__FILE__);
        wp_register_style('razwpp_css',plugins_url('css/style.css', __FILE__),array(),$razwppdata['Version']);
        wp_enqueue_style('razwpp_css');
    }

    //add settings link, nice feature to have :-)
    function set_plugin_action_links( $links, $file ) 
    {
        //Static so we don't call plugin_basename on every plugin row.
    	static $this_plugin;
    	if ( ! $this_plugin ) $this_plugin = plugin_basename(__FILE__);
    
    	if ( $file == $this_plugin ){
    	     $settings_link = '<a href="options-general.php?page=comments-manager-for-windows-phone7/wp-comments-push.php">' . esc_html( __( 'Settings', 'comments-manager-for-windows-phone7' ) ) . '</a>';
    	     array_unshift( $links, $settings_link );
        }
    	return $links;
    } // end function si_captcha_plugin_action_links
     
      /**
     * trigger_error()
     * 
     * @param (string) $error_msg
     * @param (boolean) $fatal_error | catched a fatal error - when we exit, then we can't go further than this point
     * @param unknown_type $error_type
     * @return void
     */
    function error( $error_msg, $fatal_error = false, $error_type = E_USER_ERROR )
    {
        if( isset( $_GET['action'] ) && 'error_scrape' == $_GET['action'] ) 
        {
            echo "{$error_msg}\n";
            if ( $fatal_error )
                exit;
        }
        else 
        {
            trigger_error( $error_msg, $error_type );
        }
    }

     
    public function admin_init()
    {
       
        register_setting( 'razwpp_options', 'razwpp_settings', array( &$this, 'save_settings' ) );

    } // admin_init

  public function admin_page()
  { 
    
    $img_folder=plugins_url( 'img' , __FILE__ );
    ?>
  
  <style type="text/css">
		
		
		a.razwpp_button {
			padding:4px;
			display:block;
			padding-left:25px;
			background-repeat:no-repeat;
			background-position:5px 50%;
			text-decoration:none;
			border:none;
		}
		
		a.razwpp_button:hover {
			border-bottom-width:1px;
		}
		
				
		a.razwpp_pluginHome {
			background-image:url(<?php echo $img_folder;?>/icon.gif);
		}
        
		a.razwpp_winlink {
			background-image:url(<?php echo $img_folder;?>/win.png);
		}
        
        .razwpp_info {
			background-image:url(<?php echo $img_folder;?>/info.gif);
            background-repeat: no-repeat;            
            padding-left: 20px;
		}        
        		
		a.razwpp_pluginSupport {
			background-image:url(<?php echo $img_folder;?>/wordpress.png);
		}
		.razwpp_icon64 {
			background-image:url(<?php echo $img_folder;?>/icon64.png);
            background-repeat: no-repeat;
            
            padding-left: 20px;
            padding-top: 5px;
            padding-bottom: 5px;
		}
        
       </style>
        
        <style type="text/css">
		.razwpp-padded .inside {
					margin:12px!important;
				}
		.razwpp-padded .inside ul {
					margin:6px 0 12px 0;
				}
				
		.razwpp-padded .inside input {
					padding:1px;
					margin:0;
				}	
             
                
		</style>

                     <style type="text/css" media="screen">
                    
                    div.razwppError {
                      border:1px solid <?php if ($reg_device===FALSE) echo "#c00"; else echo "#31B94D";?>;
                      padding:10px;
                      width:650px;
                      margin-top:10px;
                      -moz-border-radius:5px;
                      border-radius:5px;
                      
                    }
                    .razwppError p,
                    .razwppError h4 {margin:0;}
                  </style>
                    
<div class="wrap" id="razwpp_div">
<form method="post" action="options.php">
		<h3 class="razwpp_icon64">Comments Manager for Windows Phone <strong>7</strong>  </h3>
        
        				
				
								
<div id="poststuff" class="metabox-holder has-right-sidebar">
   <div class="inner-sidebar">
      <div id="side-sortables" class="meta-box-sortabless ui-sortable" style="position:relative;">
													
									
	<div id="razwpp_pnabout" class="postbox">
				<h3 class="hndle"><span>About this Plugin:</span></h3>
				<div class="inside">
				     <a class="razwpp_button razwpp_pluginHome" href="http://www.rlbyte.com/" target="_blank">Plugin Homepage</a>
					 <a class="razwpp_button razwpp_pluginSupport" href="mailto:support@rlbyte.com" target="_blank">Support</a>								
                     <a class="razwpp_button razwpp_pluginHome" href="http://rlbyte.com/blog/category/comments-manager/" target="_blank">Blog</a>
					 							
		          </div>

	</div>
									
						

										
						

	   <div class="inner-sidebar">
      <div id="side-sortables" class="meta-box-sortabless ui-sortable" style="position:relative;">
													
									
	<div id="razwpp_pnres" class="postbox">
				<h3 class="hndle"><span>Push Notifications Resources:</span></h3>
				<div class="inside">
				     <a class="razwpp_button razwpp_winlink" href="http://msdn.microsoft.com/en-us/library/ff402558%28v=vs.92%29.aspx" target="_blank">Push Notifications Overview</a>
					 <a class="razwpp_button razwpp_winlink" href="http://msdn.microsoft.com/en-us/library/ff941100%28v=vs.92%29.aspx" target="_blank">Push Service Response Codes</a>													 							
		          </div>

	</div>
									
						</div>
					</div>						

										
						
						</div>
					</div>									
	<div class="has-sidebar razwpp-padded" >
					
	<div id="post-body-content" class="has-sidebar-content">
						
	 <div class="meta-box-sortabless">
				
                <?php
                //get_bloginfo('version');
                global $wp_version;
                if ( $wp_version < 2.9 ) 
                { ?>
                
                  <div class="razwppError" style="border:1px solid #c00;" >
                    <p>This plugin requires at least version <strong>2.9</strong> of WordPress. Please Upgrade Your WordPress Installation.</p>
                  </div>

                <?php
                 return;
                }
                ?>						
        
      	<div id="wazwpp_opt" class="postbox">  
           <h3 class="hndle"><span>Options</span></h3>
        	<div class="inside">
            <ul>
    		
    			<?php settings_fields('razwpp_options'); ?>
    			<?php $options = get_option('razwpp_settings'); ?>
    			<table >
    				<tr valign="center"><td><input id="razwpp_settings[toast]" name="razwpp_settings[toast]" type="checkbox" value="1" <?php checked('1', $options['toast']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[toast]"><strong><abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="A message that pops up at the top of the phone screen to notify you of new comments.">Toast</abbr></strong> notifications on new comments</label> <!--<small> <strong>&#x21D2;</strong> (check/uncheck this option from your phone too)</small>--></td>
    				</tr>
    				<tr valign="center"><td ><input id="razwpp_settings[tile]" name="razwpp_settings[tile]" type="checkbox" value="1" <?php checked('1', $options['tile']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[tile]"><strong><abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="If the WP7 app is pinned to the start page it will update the tile with the new comments count since last refresh.">Tile</abbr></strong> notifications on new comments</label><!--<small> <strong>&#x21D2;</strong> (check/uncheck this option from your phone too)</small>--></td>
    				</tr>                
    				<tr valign="center"><td ><input id="razwpp_settings[nopingback]" name="razwpp_settings[nopingback]" type="checkbox" value="1" <?php checked('1', $options['nopingback']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[nopingback]">No notifications on trackbacks or pingbacks</label></td>
    				</tr>  
                    <tr valign="center"><td ><input id="razwpp_settings[nospam]" name="razwpp_settings[nospam]" type="checkbox" value="1" <?php checked('1',$options['nospam']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[nospam]">No notifications on comments marked as spam </label> </td>
    				</tr> 
                    <tr valign="center"><td ><input id="razwpp_settings[notmycomments]" name="razwpp_settings[notmycomments]" type="checkbox" value="1" <?php checked('1',$options['notmycomments']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[notmycomments]">No notifications <strong><abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="You will not receive any push notifications (tile or toast) on your own comments">on my own comments </abbr></strong></label> <label for="razwpp_settings[myid]"> &#x21D2; My User ID:</label><input id="razwpp_settings[myid]" name="razwpp_settings[myid]" type="text" size="2" value="<?php if (empty($options['myid'])) echo $this->get_current_user_id(); else echo $options['myid'] ;?>" /></td>
    				</tr> 
                    <tr valign="center"><td ><input id="razwpp_settings[notmyowncomments]" name="razwpp_settings[notmyowncomments]" type="checkbox" value="1" <?php checked('1',$options['notmyowncomments']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[notmyowncomments]">Don't <strong><abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="You will not receive your own comments to manage them (they will not be sent to WP7 app). Also check 'No notifications on my own comments' if you don't want to be bothered with your own reply comments.">manage</abbr></strong> my own comments</label> <label for="razwpp_settings[myidonc]"> &#x21D2; My User ID:</label><input id="razwpp_settings[myidonc]" name="razwpp_settings[myidonc]" type="text" size="2" value="<?php if (empty($options['myidonc'])) echo  $this->get_current_user_id(); else echo $options['myidonc'] ;?>" /></td>
    				</tr>    
                    <tr valign="center"><td ><input id="razwpp_settings[skipclosed]" name="razwpp_settings[skipclosed]" type="checkbox" value="1" <?php checked('1',$options['skipclosed']); ?> /></td>
    					<td align="left"><label for="razwpp_settings[skipclosed]">Don't <strong><abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="Check this option if you are using Wordpress feature to close comments on articles older than X days and you don't want to manage them (comments closed will not be sent to WP7 app for management).  ">manage</abbr></strong> comments on closed posts</label> </td>
    				</tr>                                    

                   
    			</table>
             <p class="submit">
    			<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    			</p>       
    	
			</ul>
			</div>            
    	</div>
        
     <div id="wazwpp_opt" class="postbox">  
           <h3 class="hndle"><span>Status</span></h3>
        	<div class="inside">
            <ul>        
<?php
                 $reg_device=get_option('razwpp_device');
                 if (empty($reg_device)) $reg_device=FALSE;
      
                  if ($reg_device===FALSE)
                  {?>
                  <div class="razwppError">
                    <p>The windows phone application was not registred with this blog yet! To enable push notification for your phone you also need to install and setup Wordpress Comments Manager on your Windows Phone device.</p>
                  </div>
                   <?php
                  }
                  else
                  {
                     $last_status=get_option( 'razwpp_laststatus','n/a');
                     $last_status_info=get_option( 'razwpp_laststatus_info','');
                     $reg_channel=get_option('razwpp_channel');
                  ?>
                  <div class="razwppError">
                    <p><strong>Device:</strong> <?php echo base64_decode($reg_device);?></p>
                    <p><strong>Push Channel:</strong> <input size="70"  type="text" readonly="yes"  value="<?php echo $reg_channel;?>" /></p>
                    <p><strong>Last <abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="This is the response of the latest Toast notification sent.">Toast</abbr>:</strong> <small>  <?php //more info on push response: http://msdn.microsoft.com/en-us/library/ff941100%28v=vs.92%29.aspx
                                                                if (is_array($last_status[0])) 
                                                                 {
                                                                  foreach ($last_status[0] as $k => $v) 
                                                                    {
                                                                      echo "$k: $v | "; //str_replace("X-","", $k).
                                                                    }
                                                                 }
                                                                 else
                                                                  echo $last_status[0];?>
                                                                  </small> </p>
                    <p><strong>Last <abbr style="border-bottom: dotted; border-bottom-width: thin; cursor: help;" title="This is the response of the latest Tile notification sent.">Tile</abbr>:</strong> <small> <?php //more info on push response: http://msdn.microsoft.com/en-us/library/ff941100%28v=vs.92%29.aspx
                                                                if (is_array($last_status[1])) 
                                                                 {
                                                                  foreach ($last_status[1] as $k => $v) 
                                                                    {
                                                                      echo "$k: $v | "; //str_replace("X-","", $k).
                                                                    }
                                                                 }
                                                                 else
                                                                  echo $last_status[1];?>
                                                                  </small> </p> 
                                                                    
                                                                  
                  <p><small><?php echo $last_status_info; ?></small></p>                                                                                                           
                  </div>
                   <?php
                  }
                ?> 
     
            </ul>
           </div>
     </div>
        
        
          </div> </div> </div> 
          
          
          	   <div class="updated">
               <p><strong class='razwpp_info'>Whats up with this "Push" thing?</strong></p>
				 <p>&nbsp;The Microsoft Push Notification Service (MPN) in Windows Phone offers third party developers a resilient, dedicated, and persistent channel to send information and updates to a Windows Phone application from their web sites (web services).</a>
                 <br />
                 In the past, a mobile application would need to frequently poll its corresponding web service to know if there are any pending notifications. While effective, polling results in the device radio being frequently turned on, impacting battery life in a negative way. By using push notifications instead of polling, a web service can notify an application of important updates on an as-needed basis.
                 </p>
					<div style="clear:right;"></div>

				</div>
          	   <div class="updated">
               <p><strong class='razwpp_info'>How do I make it work with my blog?</strong></p>
				 <p>
                 <ul>
                 <li>&#x21D2; You first need a Windows Phone 7 (<strong><font color="red">Mango</font></strong>) device.</li>
                 <li>&#x21D2; Install the wordpress plugin (you've done that already) </li>
                 <li>&#x21D2; Download <a href="http://www.windowsphone.com/en-US/apps/ccf9e4c2-bcea-4e4d-aee9-54251915d5ac">Comments Manager for Windows Phone 7</a> from your phone Marketplace</li>
                 <li>&#x21D2; Open and configure the WP7 app with your blog settings <small>(pin the app and blog to start for tiles update)</small></li>
                 <li>&#x21D2; That's All! You can now manage your blog comments directly from your phone and subscribe to push notifications and tile updates. </li>
                 </ul>
                 </p>
					<div style="clear:right;"></div>

				</div> 
             <div class="updated">
               <p><strong class='razwpp_info'>Why should I use the plugin?</strong></p>
				 <p>
                 <ul>
                 If you want to enable more features for Comment Manager for Window Phone like: 
                 <li>&#x21D2; push notifications for instant tiles update and toast messages
                 <li>&#x21D2; no notifications on trackbacks or pingbacks
                 <li>&#x21D2; skip comments on closed posts
                 <li>&#x21D2; encrypted connection to prevent packet-sniffing software from reading your sensitive data
                 <li>&#x21D2; Gzip compression when downloading comments (if the blog supports it) substantially reducing data usage and speeding up the performance                 
                 <br /> and more...
                 </ul>
                 </p>
					<div style="clear:right;"></div>

				</div>    
           </div>
           </form>
           </div>                
                                           
<?php 
             

} // admin_page
  
  public function _iscurlinstalled() 
  {
	if  (in_array  ('curl', get_loaded_extensions())) {
		return true;
	}
	else{
		return false;
	}
}

 public function save_settings( $input )
 {    
    // Our first value is either 0 or 1
	//$input['option1'] = ( $input['option1'] == 1 ? 1 : 0 );
	
	// Say our second option must be safe text with no HTML tags
	//$input['sometext'] =  wp_filter_nohtml_kses($input['sometext']);
	
    return $input;
 } // save_settings  
  
}  



function RazWPPush_init()
{
  $wpp_push = new RazWPPush();
}
add_action( 'init', 'RazWPPush_init' );

	