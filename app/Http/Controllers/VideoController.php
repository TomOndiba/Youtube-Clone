<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;

use Debugbar;
use Auth;
use App\User as User;
use App\Channel as Channel;
use App\Queue as Queue;
use App\Video as Video;
use Session;
use App\Curl\curl as Curl;
use App\Youtube\Youtube as Youtube;
use App\Youtube\YoutubeDownloader as YoutubeDownloader;


class VideoController extends Controller
{
	public function getUpload()
	{
		$channels = Auth::user()->channels;

		$channels = Channel::format($channels);

		return view('video.upload')->with([
			'channels'	=>	$channels
		]);
	}

	public function postUpload(Request $request)
	{
		dd($request);
	}

	public function getCloneVideo()
	{
		$client = new \Google_Client();
		$client->setClientId(env('GOOGLE_CLIENT_ID'));
		$client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
		$client->setScopes('https://www.googleapis.com/auth/youtube.force-ssl');
		$client->setAccessType("offline");

		$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '/video/clone/video', FILTER_SANITIZE_URL);
		$client->setRedirectUri($redirect);

		$youtube = new \Google_Service_YouTube($client);

		/**
		 * Google Auth
		 */
		if (isset($_GET['code'])) {
			if (strval(Session::get('state')) !== strval($_GET['state'])) {
				die('The session state did not match.');
			}

			$client->authenticate($_GET['code']);

			Session::put('google_api_token',$client->getAccessToken());

			if (Session::get('google_api_token') !== null) {
				$client->setAccessToken(Session::get('google_api_token'));
			}

			if ($client->getAccessToken()) {

				$output = Curl::exec("https://www.googleapis.com/youtube/v3/channels?part=snippet%2CbrandingSettings%2CinvideoPromotion%2Cstatus&mine=true&key=".env('YOUTUBE_API_KEY')."&access_token=".Session::get('google_api_token.access_token'));

				Debugbar::info($output);

				Session::put('google_api_token', $client->getAccessToken());

				$is_exists_with_auth = Channel::is_exists_with_auth(Auth::user(), $output->items[0]->id);

				if(isset($output->items[0])) {
					$info = array();
					$info['snippet'] = $output->items[0]->snippet;
					$info['status'] = $output->items[0]->status;
					$info['branding_settings'] = $output->items[0]->brandingSettings;

					$info = json_encode($info);

					Session::put('current_auth', $output->items[0]->id);

					if($is_exists_with_auth) {

						// update
						Channel::where('user_id', Auth::user()->id)->where('youtube_channel_id', $output->items[0]->id)->update([
							"name"	=>	$output->items[0]->snippet->title,
							"info"	=>	$info
						]);

					} else {

						// insert
						Channel::create([
							"user_id"				=>	Auth::user()->id,
							"youtube_channel_id"	=>	$output->items[0]->id,
							"name"					=>	$output->items[0]->snippet->title,
							"info"					=>	$info
						]);

					}
				}
			}
		}
		/** End Google Auth **/

		$channels = Auth::user()->channels;

		$channels = Channel::format($channels);

		return view('video.clone.video')->with([
			'channels'	=>	$channels
		]);
	}

	public function postCloneVideo(Request $request)
	{

		ini_set('memory_limit', '1024M');

		$rules = [
			'url'	=>	'required',
			'channel'	=>	'required'
		];

		$this->validate($request,$rules);

		$urls = explode("\r\n",$request->input('url'));

		$videos = array();

		foreach($urls as $url) {
			
			$youtube_downloader = new YoutubeDownloader();
			if($data = $youtube_downloader->keepvid($url)) {
				$videos[] = $data;
			}

		}

		if($videos)
		{
			$queue_id = Queue::create([
				'channel_id' => Session::get('current_auth'),
				'user_id'	=>	Auth::user()->id,
			])->id;

			$client = new \Google_Client();
			$client->setClientId(env('GOOGLE_CLIENT_ID'));
			$client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
			$client->setScopes('https://www.googleapis.com/auth/youtube.force-ssl');
			$client->setAccessType("offline");

			$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '/video/clone/video', FILTER_SANITIZE_URL);
			$client->setRedirectUri($redirect);

			$youtube = new \Google_Service_YouTube($client);

			$data = array();

			foreach($videos as $video) {
				$output = Curl::exec("https://www.googleapis.com/youtube/v3/videos?part=snippet&id=".$video['id']."&key=".env('YOUTUBE_API_KEY')."&access_token=".Session::get('google_api_token.access_token'));

				Session::put('google_api_token', $client->getAccessToken());

				if(isset($output->items[0])) {

					$data[] = [
						"youtube_video_id"		=>	"",
						"user_id"				=>	Auth::user()->id,
						"youtube_channel_id"	=>  Session::get('current_auth'),
						"source_channel_id"		=>	$output->items[0]->snippet->channelId,
						"title"					=> 	$output->items[0]->snippet->title,
						"description"			=>	$output->items[0]->snippet->description,
						"setCategoryId"			=>	$output->items[0]->snippet->categoryId,
						"tags"					=>	isset($output->items[0]->snippet->tags) ? json_encode($output->items[0]->snippet->tags) : json_encode(array()),
						"info"					=>	json_encode($output->items[0]->snippet),
						"status"				=>	"public",
						"link_download"			=>	"",
						"queue_id"				=>	$queue_id,
						"source_video_id"		=>	$output->items[0]->id
					];

				}
			}

			if($data) {
				Video::insert($data);
			}

			return redirect('/')->with(["data" => $data]);
		}
	}

	public function getCloneChannel()
	{
		dd('get Clone channel');
	}

	public function getAuth($id) {
		$current_id = Session::get('current_auth');

		$redirect = true;

		if($current_id === $id) {
			$redirect = false;
		}

		if($redirect) {

			$client = new \Google_Client();
			$client->setClientId(env('GOOGLE_CLIENT_ID'));
			$client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
			$client->setScopes('https://www.googleapis.com/auth/youtube.force-ssl');

			$client->setRedirectUri(filter_var(strtok($_SERVER['HTTP_REFERER'],'?'), FILTER_SANITIZE_URL));

			$youtube = new \Google_Service_YouTube($client);

			$state = mt_rand();
			$client->setState($state);
			Session::put('state',$state);

			$authUrl = $client->createAuthUrl();
		}

		return json_encode(array("redirect"=>$redirect, 'authUrl' => $authUrl));
	}

}
