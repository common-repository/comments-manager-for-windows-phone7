<?php

class WindowsPhonePushPriority
{
    const TileImmediately = 1;
    const ToastImmediately = 2;
    const RawImmediately = 3;
    const TileWait450 = 11;
    const ToastWait450 = 12;
    const RawWait450 = 13;
    const TileWait900 = 21;	
    const ToastWait900 = 22;	
    const RawWait900 = 23;
}

class WindowsPhonePushClient
{
    private $device_url = '';
    private $debug_mode = false;
    public  $channel_disabled=false;
    public  $response_info;
    
    function __construct($device_url)
    {
        $this->device_url = $device_url;
    }
    
    public function send_raw_update($msg, $priority = WindowsPhonePushPriority::RawImmediately)
    {
        return $this->_send_push(array('X-NotificationClass: ' . $priority), $msg);
    }
    
    public function send_tile_update($image_url, $count, $title, $priority = WindowsPhonePushPriority::TileImmediately,$tile_id="")
    {
        if (!empty($tile_id))
         $tile_id="ID=\"$tile_id\"";//  ID=\"/SecondaryTile.xaml?DefaultTitle=FromTile\"
        if (!empty($title))
         $title="<wp:Title>" . $title . "</wp:Title>";
        
        $msg = "<?xml version=\"1.0\" encoding=\"utf-8\"?>" .
                "<wp:Notification xmlns:wp=\"WPNotification\">" .
                   "<wp:Tile $tile_id>".
                      "<wp:BackgroundImage>" . $image_url . "</wp:BackgroundImage>" .
                      "<wp:Count>" . $count . "</wp:Count>" .
                      $title .
                   "</wp:Tile> " .
                "</wp:Notification>";

        return $this->_send_push(array(
                                    'X-WindowsPhone-Target: token',
                                    'X-NotificationClass: ' . $priority,
                                ), $msg);
    }    
    
    
    public function send_toast($title, $message, $priority = WindowsPhonePushPriority::ToastImmediately)
    {
        $msg = "<?xml version=\"1.0\" encoding=\"utf-8\"?>" .
            "<wp:Notification xmlns:wp=\"WPNotification\">" .
                "<wp:Toast>" . 
                    "<wp:Text1>" . $title . "</wp:Text1>" .
                    "<wp:Text2>" . $message . "</wp:Text2>" .
                "</wp:Toast>" .
            "</wp:Notification>";
        
        return $this->_send_push(array(
                                      'X-WindowsPhone-Target: toast',
                                      'X-NotificationClass: ' . $priority, 
                                      ), $msg);
                                      
    }
    
    private function _send_push($headers, $msg)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->device_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, true); 
        curl_setopt($ch, CURLOPT_HTTPHEADER,    // Add these headers to all requests
            $headers + array(
                            'Content-Type: text/xml',
                            'Accept: application/*'
                            )
            ); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, $msg);

        if ($this->debug_mode)
        {
            curl_setopt($ch, CURLOPT_VERBOSE, $this->debug_mode);
            curl_setopt($ch, CURLOPT_STDERR, fopen('debug.log','w'));
        }
        $output = curl_exec($ch);
        curl_close($ch);
        
        $Notification=trim($this->_get_header_value($output, 'X-NotificationStatus'));
        $DeviceConnection = trim($this->_get_header_value($output, 'X-DeviceConnectionStatus'));
        $Subscription=trim($this->_get_header_value($output, 'X-SubscriptionStatus'));
        
        if (empty($Subscription) || strtolower($Subscription)!="active" || strtolower($Notification)=="dropped")
          $this->channel_disabled=true;
        else
          $this->channel_disabled=false;
        
        $this->response_info=$this->_get_response_info(strtolower($Notification),strtolower($DeviceConnection),strtolower($Subscription));
        
        return array(
            'Notification'     => $Notification,
            'DeviceConnection' => $DeviceConnection,            
            'Subscription'     => $Subscription,            
            'On'=>date("j M, H:i")
            );
    }
    
    
    private function _get_response_info($Notification,$DeviceConnection,$Subscription)
    {
        $DeviceConnection=str_replace(" ","",$DeviceConnection);
        if ($Notification=="received" && $DeviceConnection=="connected" && $Subscription=="active" )
            return "The notification request was accepted and queued for delivery. This status does not mean the device has received the notification. It indicates only that the server has received the notification and queued it for delivery at the next possible opportunity for the device.";
        else if ($Notification=="received" && $DeviceConnection=="temporarilydisconnected" && $Subscription=="active" ) 
           return "The notification request was accepted and queued for delivery. However, the device is temporarily disconnected.";
        else if ( ($Notification=="queuefull" &&  $Subscription=="active") && ($DeviceConnection=="connected" || $DeviceConnection=="temporarilydisconnected" ) )
           return "Queue overflow.";
        else if ( ($Notification=="suppressed" &&  $Subscription=="active") && ($DeviceConnection=="connected" || $DeviceConnection=="tempdisconnected" ) )
           return "The push notification was received and dropped by the Push Notification Service. The Suppressed status can occur if the notification channel was configured to suppress push notifications for a particular push notification class.";                      
       else if (empty($Notification) && empty ($DeviceConnection) && empty($Subscription))
           return "The Push Notification Service is unable to process the request.";
       else if ($Notification=="dropped" && $Subscription=="expired" ) 
           return "The subscription is invalid and is not present on the Push Notification Service. Further notifications will be disabled.";
       else if ($Notification=="dropped" && $Subscription=="active" ) 
           return "This error occurs when an unauthenticated web service has reached the per-day throttling limit for a subscription. Unauthenticated web services are throttled at a rate of 500 push notifications per subscription per day. ";
       else if ($Notification=="dropped" && $DeviceConnection=="inactive" && empty($Subscription)) 
            return "The device is in an inactive state. Try restarting your device.";
       else
        return "";      
    }


    private function _get_header_value($content, $header)
    {
        return preg_match_all("/$header: (.*)/i", $content, $match) ? trim($match[1][0]) : "";
    }
}

?>