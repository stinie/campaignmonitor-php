<?php
/**
* @package CampaignMonitor
* @version 1.1
* @author Kaiser Shahid <www.qaiser.net>
* @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
* @license http://opensource.org/licenses/lgpl-3.0.html GNU Lesser General Public License (LGPLv3)
* @link http://code.google.com/p/campaignmonitor-php/ Campaign Monitor PHP
* @see http://www.campaignmonitor.com/api/
*
* This is an all-inclusive package for interfacing with Campaign Monitor's services. It
* supports SOAP, GET, and POST seamlessly (just set the $method property to 'soap', 'get', 
* or 'post' before making a call) and always returns the same view of data regardless of
* the method used to call the service.
*
* On top of that, it comes with a near-complete set of functions that encapsulate the current
* API offerings. To make calls directly, look at the makeCall() method.
* 
* All class methods correspond directly to the API methods as follows:
* 1) the '.' is removed
* 2) the first character is lower-cased
*/

class CampaignMonitor
{
	protected
		$api = ''
		, $campaign_id = 0
		, $client_id = 0
		, $list_id = 0
	;

	public
		$method = 'get'
	;

	// debugging options
	public
		$debug_level = 0
		, $debug_request = ''
		, $debug_response = ''
		, $debug_url = ''
		, $debug_info = array()
		, $show_response_headers = 0
	;

	public function __construct( $api = null, $client = null, $campaign = null, $list = null, $method = 'get' )
	{
		$this->api = $api;
		$this->client_id = $client;
		$this->campaign_id = $campaign;
		$this->list_id = $list;
		$this->method = $method;
	}

	public function makeCall( $action = '', $options = array() )
	{
		if ( !$action ) return null;
		$url = 'http://app.campaignmonitor.com/api/api.asmx';

		// TODO: like facebook's client, allow for get/post through the file wrappers
		// if curl isn't available. (or maybe have curl-emulating functions defined 
		// at the bottom of this script.)

		$ch = curl_init();
		if ( !isset( $options['header'] ) )
			$options['header'] = array();

		$postdata = '';

		if ( $this->method == 'soap' )
		{
			$options['header'][] = 'Content-Type: text/xml; charset=utf-8';
			$options['header'][] = 'SOAPAction: "http://app.campaignmonitor.com/api/' . $action . '"';

			$postdata = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
			$postdata .= "<soap:Envelope xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"";
			$postdata .= " xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\"";
			$postdata .= " xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\">\n";
			$postdata .= "<soap:Body>\n";
			$postdata .= "	<{$action} xmlns=\"http://app.campaignmonitor.com/api/\">\n";
			$postdata .= "		<ApiKey>{$this->api}</ApiKey>\n";

			if ( isset( $options['params'] ) )
				$postdata .= CampaignMonitor::convertArrayToXML( $options['params'], "\t\t" );

			$postdata .= "	</{$action}>\n";
			$postdata .= "</soap:Body>\n";
			$postdata .= "</soap:Envelope>";

			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		}
		else
		{
			$postdata = "ApiKey={$this->api}";
			$url .= "/{$action}";

			// NOTE: since this is GET, the assumption is that params is a set of simple key-value pairs.
			if ( isset( $options['params'] ) )
			{
				foreach ( $options['params'] as $k => $v )
					$postdata .= '&' . $k . '=' . urlencode( $v );
			}

			if ( $this->method == 'get' )
				$url .= '?' . $postdata;
			else
			{
				$options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
				curl_setopt( $ch, CURLOPT_POST, 1 );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			}
		}

		curl_setopt_array( $ch, 
			array(
				CURLOPT_URL => $url
				, CURLOPT_RETURNTRANSFER => 1
				, CURLOPT_HTTPHEADER => $options['header']
				, CURLOPT_HEADER => $this->show_response_headers
			)
		);

		// except for the response, all other information will be stored when debugging is on.
		$res = curl_exec( $ch );
		if ( $this->debug_level )
		{
			$this->debug_url = $url;
			$this->debug_request = $postdata;
			$this->debug_info = curl_getinfo( $ch );
			$this->debug_info['headers_sent'] = $options['header'];
		}
		$this->debug_response = $res;
		curl_close( $ch );

		if ( $res )
		{

			if ( $this->method == 'soap' )
			{
				$res = str_replace( array( '<soap:Body>', '</soap:Body>' ), '', $res );
				$tmp = CampaignMonitor::convertXMLToArray( $res );
				if ( !is_array( $tmp ) )
					return $tmp;
				else
					return $tmp[$action.'Response'][$action.'Result'];
			}
			else
				return CampaignMonitor::convertXMLToArray( $res );
		}
		else
			return null;
	}

	/**
	* subscribersGetActive()
	* @param mixed $date If a string, should be in the date() format of 'Y-m-d H:i:s', otherwise, a Unix timestamp.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param string $action (Optional) Set the actual API method to call. Defaults to Subscribers.GeActive if no other valid value is given.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetActive.aspx
	*
	* Wrapper for Subscribers.GetActive. This method triples as Subscribers.GetUnsubscribed 
	* and Subscribers.GetBounced when the very last parameter is overridden.
	*/

	public function subscribersGetActive( $date  = 0, $list_id = null, $action = 'Subscribers.GetActive' )
	{
		if ( !$list_id )
			$list_id = $this->list_id;

		if ( is_numeric( $date ) )
			$date = date( 'Y-m-d H:i:s', $date );

		$valid_actions = array( 'Subscribers.GetActive' => '', 'Subscribers.GetUnsubscribed' => '', 'Subscribers.GetBounced' => '' );
		if ( !isset( $valid_actions[$action] ) )
			$action = 'Subscribers.GetActive';

		return $this->makeCall( $action
			, array( 
				'params' => array( 
					'ListID' => $list_id 
					, 'Date' => $date
				)
			)
		);
	}

	/**
	* subscribersGetUnsubscribed()
	* @param mixed $date If a string, should be in the date() format of 'Y-m-d H:i:s', otherwise, a Unix timestamp.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetUnsubscribed.aspx
	*/

	public function subscribersGetUnsubscribed( $date  = 0, $list_id = null )
	{
		return $this->subscribersGetActive( $date, $list_id, 'Subscribers.GetUnsubscribed' );
	}

	/**
	* subscribersGetBounced()
	* @param mixed $date If a string, should be in the date() format of 'Y-m-d H:i:s', otherwise, a Unix timestamp.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetBounced.aspx
	*/

	public function subscribersGetBounced( $date  = 0, $list_id = null )
	{
		return $this->subscribersGetActive( $date, $list_id, 'Subscribers.GetBounced' );
	}

	/**
	* subscriberAdd()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $resubscribe If true, does an equivalent 'AndResubscribe' API method.
	* @see http://www.campaignmonitor.com/api/Subscriber.Add.aspx
	*/

	public function subscriberAdd( $email, $name, $list_id = null, $resubscribe = false )
	{
		if ( !$list_id )
			$list_id = $this->list_id;

		$action = 'Subscriber.Add';
		if ( $resubscribe ) $action = 'Subscriber.AddAndResubscribe';

		return $this->makeCall( $action
			, array(
				'params' => array(
					'ListID' => $list_id
					, 'Email' => $email
					, 'Name' => $name
				)
			)
		);
	}

	/**
	* subscriberAddAndResubscribe()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @see http://www.campaignmonitor.com/api/Subscriber.AddAndResubscribe.aspx
	*/

	public function subscriberAddAndResubscribe( $email, $name, $list_id = null )
	{
		return $this->subscriberAdd( $email, $name, $list_id, true );
	}

	/**
	* subscriberAddRedundant()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* 
	* This encapsulates the check of whether this particular user unsubscribed once.
	*/

	public function subscriberAddRedundant( $email, $name, $list_id = null )
	{
		$added = $this->subscriberAdd( $email, $name, $list_id );
		if ( $added && $added['Code'] == '0' )
		{
			$subscribed = $this->subscribersGetIsSubscribed( $email, $list_id );
			// Must have unsubscribed, so resubscribe
			if ( $subscribed == 'False' )
			{
				// since we're internal, we'll just call the method with full parameters rather
				// than go through a secondary wrapper function.
				$added = $this->subscriberAdd( $email, $name, $list_id, true );
				return $added;
			}
		}

		return $added;
	}

	/**
	* subscriberAddWithCustomFields()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param mixed $fields Should only be a single-dimension array of key-value pairs.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $resubscribe If true, does an equivalent 'AndResubscribe' API method.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscriber.AddWithCustomFields.aspx
	*/

	public function subscriberAddWithCustomFields( $email, $name, $fields, $list_id = null, $resubscribe = false )
	{
		if ( !$list_id )
			$list_id = $this->list_id;

		$action = 'Subscriber.AddWithCustomFields';
		if ( $resubscribe ) $action = 'Subscriber.AddAndResubscribeWithCustomFields';

		if ( !is_array( $fields ) )
			$fields = array();

		$_fields = array(/* 'SubscriberCustomField' => array() */);
		foreach ( $fields as $k => $v )
			//$_fields['SubscriberCustomField'][] = array( 'Key' => $k, 'Value' => $v );
			$_fields[] = array( 'Key' => $k, 'Value' => $v );

		return $this->makeCall( $action
			, array(
				'params' => array(
					'ListID' => $list_id
					, 'Email' => $email
					, 'Name' => $name
					, 'CustomFields' => $_fields
				)
			)
		);
	}

	/**
	* subscriberAddAndResubscribeWithCustomFields()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param mixed $fields Should only be a single-dimension array of key-value pairs.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $resubscribe If true, does an equivalent 'AndResubscribe' API method.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscriber.AddAndResubscribeWithCustomFields.aspx
	*/

	public function subscriberAddAndResubscribeWithCustomFields( $email, $name, $fields, $list_id = null )
	{
		return $this->subscriberAddWithCustomFields( $email, $name, $fields, $list_id, true );
	}

	/**
	* subscriberAddWithCustomFieldsRedundant()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* 
	* Same as subscriberAddRedundant() except with CustomFields.
	*/

	public function subscriberAddWithCustomFieldsRedundant( $email, $name, $fields, $list_id = null )
	{
		$added = $this->subscriberAddWithCustomFields( $email, $name, $fields, $list_id );
		if ( $added && $added['Code'] == '0' )
		{
			$subscribed = $this->subscribersGetIsSubscribed( $email );
			if ( $subscribed == 'False' )
			{
				$added = $this->subscriberAddWithCustomFields( $email, $name, $fields, $list_id, true );
				return $added;
			}
		}

		return $added;
	}

	/**
	* subscriberUnsubscribe()
	* @param string $email Email address.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $check_subscribed If true, does the Subscribers.GetIsSubscribed API method instead.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscriber.Unsubscribe.aspx
	*/

	public function subscriberUnsubscribe( $email, $list_id = null, $check_subscribed = false )
	{
		if ( !$list_id )
			$list_id = $this->list_id;

		$action = 'Subscriber.Unsubscribe';
		if ( $check_subscribed ) $action = 'Subscribers.GetIsSubscribed';

		return $this->makeCall( $action
			, array(
				'params' => array(
					'ListID' => $list_id
					, 'Email' => $email
				)
			)
		);
	}

	/**
	* subscribersGetIsSubscribed()
	* @return string A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetIsSubscribed.aspx
	*/

	public function subscribersGetIsSubscribed( $email, $list_id = null )
	{
		return $this->subscriberUnsubscribe( $email, $list_id, true );
	}

	/**
	* checkSubscriptions()
	* @param string $email User's email
	* @param mixed $lists An associative array of lists to check against. Each key should be a List ID
	* @param boolean $no_assoc If true, only returns an array where each value indicates that the user is subscribed
	*        to that particular list. Otherwise, returns a fully associative array of $list_id => true | false.
	* @return mixed An array corresponding to $lists where true means the user is subscribed to that particular list.
	*
	* Given an array of lists, indicate whether the $email is subscribed to each of those lists.
	*/

	public function checkSubscriptions( $email, $lists, $no_assoc = true )
	{
		$nlist = array();
		foreach ( $lists as $lid => $misc )
		{
			$val = $this->subscribersGetIsSubscribed( $email, $lid );
			$val = $val != 'False';
			if ( $no_assoc && $val ) $nlist[] = $lid;
			elseif ( !$no_assoc ) $nlist[$lid] = $val;
		}

		return $nlist;
	}

	/**
	* clientGetLists()
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Client.GetLists.aspx
	*/

	public function clientGetLists( $client_id = null )
	{
		if ( !$client_id )
			$client_id = $this->client_id;

		return $this->makeCall( 'Client.GetLists'
			, array(
				'params' => array(
					'ClientID' => $client_id
				)
			)
		);
	}

	/**
	* clientGetListsDropdown()
	*
	* Creates an associative array with list_id => List_label pairings.
	*/

	public function clientGetListsDropdown( $client_id = null )
	{
		$lists = $this->clientGetLists( $client_id );
		if ( !isset( $lists['List'] ) )
			return null;
		else
			$lists = $lists['List'];

		$_lists = array();

		if ( isset( $lists[0] ) )
		{
			foreach ( $lists as $list )
				$_lists[$list['ListID']] = $list['Name'];
		}
		else
			$_lists[$lists['ListID']] = $lists['Name'];

		return $_lists;
	}

	/**
	* clientGetCampaigns()
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Client.GetCampaigns.aspx
	*/

	public function clientGetCampaigns( $client_id = null )
	{
		if ( !$client_id )
			$client_id = $this->client_id;

		return $this->makeCall( 'Client.GetCampaigns'
			, array(
				'params' => array(
					'ClientID' => $client_id
				)
			)
		);
	}

	/**
	* userGetClients()
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/User.GetClients.aspx
	*/

	public function userGetClients()
	{
		return $this->makeCall( 'User.GetClients' );
	}

	/**
	* userGetSystemDate()
	* @return string A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/User.GetSystemDate.aspx
	*/

	public function userGetSystemDate()
	{
		return $this->makeCall( 'User.GetSystemDate' );
	}

	/**
	* convertXMLToArray()
	*
	* @param string $str (Optional) A valid XML fragment
	* @param SimpleXML $_xml (Optional) An iterable instance of SimpleXML. This would be used in place of $str if not null.
	* @return mixed An array of values.
	* @todo Allow an XPath starting point for the XML?
	*
	* This static function (which works well when only dealing with values and elements), will return an associative
	* array where each element is either an associate array or a 'list' (with each element also following the 
	* same rule of being an associative array or list). A good, quick test to see what an element is is as follows:
	*
	* if ( isset( $element[0] ) ) { // list }
 	* else { // associative  }
	*
	*/
	public static function convertXMLToArray( $str, $_xml = null )
	{
		if ( $_xml )
			$xml = $_xml;
		else
			$xml = simplexml_load_string( trim($str) );

		$arr = array();
		$conv_to_arr = array();

		foreach ( $xml as $node => $data )
		{
			$node = (string) $node;

      // If node has no relevant value then use non-associative array - Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
			if ('int' == $node || 'string' == $node)
			  $node = '';

			// $children available, so convert the nodes into an array,
			// otherwise, grab the value of the current node.
			$children = $data->children();
			if ( $children )
				$tmp = self::convertXMLToArray( null, $children );
			else
				$tmp = trim( (string) $data );

			// if the element $node exists, check first if it's been
			// converted to a list. if not, make a list, otherwise, append to list.
			if ( isset( $arr[$node] ) )
			{
				if ( !isset( $conv_to_arr[$node] ) )
				{
					$_tmp = $arr[$node];
					$arr[$node] = array( $_tmp, $tmp );
					$conv_to_arr[$node] = true;	
				}
				else
					$arr[$node][] = $tmp;
			}
			else
				$arr[$node] = $tmp;
		}

		// this node must only be a value
		if ( !$arr )
			return (string) $xml;

		return $arr;
	}

	/**
	* convertArrayToXML()
	*
	* @param mixed $arr The associative to convert to an XML fragment
	* @param string $indent (Optional) Starting identation of each element
	* @return string An XML fragment.
	*
	* The opposite of convertXMLToArray().
	*/

	public static function convertArrayToXML( $arr, $indent = '' )
	{
		$buff = '';

		foreach ( $arr as $k => $v )
		{
		  // If array is not associative then key is type - Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
		  if ( is_int( $k ) && is_int( $v ) )
		    $k = 'int';
		  elseif ( is_int( $k ) && is_string( $v ) )
		    $k = 'string';
		  elseif ( is_int( $k ) && is_array( $v ) )
		    $k = 'SubscriberCustomField';

			if ( !is_array( $v ) )
				$buff .= "$indent<$k>" . htmlentities( $v ) . "</$k>\n";
			else
				/* As of 1.1 this has switched to straight recursion to avoid problems  with Campaign.Create's nested values.
				   Previously used a nested foreach loop - Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz> */
				$buff .= "$indent<$k>\n" . self::convertArrayToXML( $v, $indent . "\t" ) . "$indent</$k>\n";
		}

		return $buff;
	}

################################################## NEW IN 1.1 ##################################################

  /**
   * subscribersGetSingleSubscriber()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param string $email User's email
   * @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Subscriber.GetSingleSubscriber.aspx
   **/

  public function subscribersGetSingleSubscriber( $email, $list_id = null )
  {
    if ( !$list_id )
  		$list_id = $this->list_id;

    return $this->makeCall( 'Subscribers.GetSingleSubscriber'
      , array(
  			'params' => array(
  				'ListID' => $list_id
  				, 'EmailAddress' => $email
  			)
  		)
  	);
  }

  /**
   * clientGetSegments()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Client.GetSegments.aspx
   **/

  public function clientGetSegments( $client_id = null )
  {
  	if ( !$client_id )
  		$client_id = $this->client_id;

  	return $this->makeCall( 'Client.GetSegments'
  		, array(
  			'params' => array(
  				'ClientID' => $client_id
  			)
  		)
  	);
  }

  /**
   * campaignCreate()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param string $campaign_name The name of the new campaign. This must be unique across all draft campaign for the client
   * @param string $campaign_subject The subject of the new campaign
   * @param string $from_name The name to appear in the From field in the recipients email client when they receive the new campaign
   * @param string $from_email The email address that the new campaign will come from
   * @param string $reply_to The email address that any replies to the new campaign will be sent to
   * @param string $html_url The URL of the HTML content for the new campaign. If no unsubscribe link is found then one will be
   *                         added automatically. Styling in the campaign will be automatically brought inline
   * @param string $text_url The URL of the Text content for the new campaign. If no unsubscribe link is found then one will be
   *                         added automatically.
   * @param array $subscriber_list_ids An array of lists to send the campaign to.
   * @param array $list_segments An array of Segment Names and their appropriate List ID's to send the campaign to.
   * @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.Create.aspx
   *
   * For $list_segments please use the format as is returned by clientGetSegments()
   **/

  public function campaignCreate ( $campaign_name, $campaign_subject, $from_name, $from_email, $reply_to, $html_url, $text_url,
                                   $subscriber_list_ids = null, $list_segments = null, $client_id = null )
  {
  	if ( !$client_id ) $client_id = $this->client_id;

  	$params = array(
			'ClientID' => $client_id
			, 'CampaignName' => $campaign_name
			, 'CampaignSubject' => $campaign_subject
			, 'FromName' => $from_name
			, 'FromEmail' => $from_email
			, 'ReplyTo' => $reply_to
			, 'HtmlUrl' => $html_url
			, 'TextUrl' => $text_url
		);

		if ( $subscriber_list_ids )
  	  $params['SubscriberListIDs'] = $subscriber_list_ids;

  	if ( $list_segments )
  	  $params['ListSegments'] = $list_segments;

  	return $this->makeCall( 'Campaign.Create', array('params' => $params));
  }

  /**
   * campaignSend()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param string $confirmation_email The email address that the confirmation email that the campaign has been sent, will go to.
   * @param string $send_date The date the campaign should be scheduled to be sent. To send a campaign immediately pass
   *                          in "Immediately". This date should be in the users timezone and formatted as YYYY-MM-DD HH:MM:SS.
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.Send.aspx
   **/

  public function campaignSend( $send_date = 'Immediately', $confirmation_email, $campaign_id = null )
  {
    if ( !$campaign_id ) $campaign_id = $this->campaign_id;
    
    if (strtotime($send_date) <= time() || $send_date = 'Immediately')
      $senddate = 'Immediately';
    else
      $senddate = date('Y-m-d H:i:s', strtotime($senddate)); // YYYY-MM-DD HH:MM:SS
    
    if ( $send_date == 'Immediately')
    {
      $date = new DateTime($this->userGetSystemDate());
      $date->modify('+5 seconds');  // Created 5 second delay for 'Immediate' to avoid 'Delivery Date Cannot be in the Past' error
      $send_date = $date->format('Y-m-d H:i:s');
    }

  	return $this->makeCall( 'Campaign.Send'
  		, array(
  			'params' => array(
  				'CampaignID' => $campaign_id
  				, 'ConfirmationEmail' => $confirmation_email
  				, 'SendDate' => $send_date
  			)
  		)
  	);
  }

  /**
   * campaignGetSummary()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.GetSummary.aspx
   **/

  public function campaignGetSummary( $campaign_id = null )
  {
  	return $this->campaignSimpleAction( 'Campaign.GetSummary', $campaign_id );
  }

  /**
   * campaignGetOpens()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.GetOpens.aspx
   **/

  public function campaignGetOpens( $campaign_id = null )
  {
  	return $this->campaignSimpleAction( 'Campaign.GetOpens', $campaign_id );
  }

  /**
   * campaignGetBounces()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.GetBounces.aspx
  **/

  public function campaignGetBounces( $campaign_id = null )
  {
  	return $this->campaignSimpleAction( 'Campaign.GetBounces', $campaign_id );
  }

  /**
   * campaignGetSubscriberClicks()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.GetSubscriberClicks.aspx
   **/

  public function campaignGetSubscriberClicks( $campaign_id = null )
  {
  	return $this->campaignSimpleAction( 'Campaign.GetSubscriberClicks', $campaign_id );
  }

  /**
   * campaignGetUnsubscribes()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.GetUnsubscribes.aspx
   **/

  public function campaignGetUnsubscribes( $campaign_id = null )
  {
  	return $this->campaignSimpleAction( 'Campaign.GetUnsubscribes', $campaign_id );
  }

  /**
   * campaignGetLists()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   * @see http://www.campaignmonitor.com/api/Campaign.GetLists.aspx
   **/

  public function campaignGetLists( $campaign_id = null )
  {
  	return $this->campaignSimpleAction( 'Campaign.GetLists', $campaign_id );
  }

  /**
   * campaignSimpleAction()
   * @author Keri Henare (Pixel Fusion) <www.pixelfusion.co.nz>
   * @param string $action API action to execute.
   * @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
   * @return mixed A parsed response from the server, or null if something failed.
   *
   * Simplification of a basic Campaign request
   **/

  public function campaignSimpleAction( $action, $campaign_id = null )
  {
    if ( !$campaign_id ) $campaign_id = $this->campaign_id;

  	return $this->makeCall( $action
  		, array(
  			'params' => array(
  				'CampaignID' => $campaign_id
  			)
  		)
  	);
  }

}

/*
// Example Usage
$cm = new CampaignMonitor( $api_key, $client_id, $campaign_id, $list_id );

// let's get some information using SOAP. we want to get all lists for a client, 
// and show active users in each. we'll also turn debugging on.

$cm->method = 'soap';
$cm->debug_level = 1;

// this will use the default $client_id that we created the object with. you can 
// pass a specific ClientID as the first parameter.

$lists = $cm->clientGetLists();

// note that we're checking if the set of ListIDs is a typical 0-indexed array:
// if there's more than one ListID returned, convertXMLToArray() handles those 
// elements in that way, instead of as a dictionary (otherwise, the key value
// 'Lists' would be overwritten with each new element). we'll get into this more
// in just a moment.

if ( isset( $lists['Lists'][0] ) )
{
	foreach ( $lists['Lists'] as $list )
	{
		$users = $cm->subscribersGetActive( $list['ListID'] );
		echo "{$list['ListName']} ({$list['ListID']})<br />";
		print_r( $users );
	}
}
elseif ( $lists )
{
	$users = $cm->subscribersGetActive( $lists['Lists']['ListID'] );
	echo "{$lists['Lists']['ListName']} ({$lists['Lists']['ListID']})<br />";
	print_r( $users );
}

// since we have debug turned on, let's compare the XML responsee to the array
// values. you'll see that all the extra wrappers elements are discarded: makeCall()
// always only returns the data you need to work with. 
// this sort of debugging is useful starting out if you want to know the XML relates 
// to the returned array.

echo '<pre>' . htmlentities( $cm->debug_response ) . '</pre>';
print_r( $lists );

// it might make more sense to see how an array is converted to XML to fully
// make sense of the conventions used. note that you'll need to view the source
// to see the markup.

$list1 = array( 'ParentElement' => 
	array( 'Child1' => 'Value1', 'Child2' => 'Value2' ) 
	);
print_r( CampaignMonitor::convertArrayToXML( $list1 ) );

/ *
this should output:

<ParentElement>
	<Child1>Value1</Child1>
	<Child2>Value2</Child2>
</ParentElement>
* /

$list2 = array( 'ParentElement' =>
	array(
			array(
				'Child1' => 'Value1'
				, 'Child2' => 'Value2'
			)
		)
	);
print_r( CampaignMonitor::convertArrayToXML( $list2 ) );

/ *
this should output:

<ParentElement>
	<Child1>Value1</Child1>
</ParentElement>
<ParentElement>
	<Child2>Value2</Child2>
</ParentElement>
* /

// basically, in order to have multiple ParentElement elements, the
// corresponding value of that key needs to be a 0-indexed array.
*/
?>