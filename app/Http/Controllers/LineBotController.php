<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use LINE\LINEBot;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Event\MessageEvent\LocationMessage;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use GuzzleHttp\Client;

class LineBotController extends Controller
{
  // テスト用オウム返しメソッド
  public function parrot(Request $request)
  {
    // botインスタンス生成
    $httpClient = new CurlHTTPClient(env('LINE_ACCESS_TOKEN'));
    $lineBot = new LINEBot($httpClient, ['channelSecret' => env('LINE_CHANNEL_SECRET')]);
    
    // セキュリティのため署名を確認
    $signature = $request->header('x-line-signature');
    if (!$lineBot->validateSignature($request->getContent(), $signature)) {
        abort(400, 'Invalid signature');
    }
    $events = $lineBot->parseEventRequest($request->getContent(), $signature);
    
    foreach($events as $event){
      if (!($event instanceof TextMessage)) {
        continue;
      }

      $replyToken = $event->getReplyToken();
      $replyText = $event->getText();
      $lineBot->replyText($replyToken, $replyText);
    }
  }

  /**
   * 洗濯ものを織り込むべきかを教えてくれるメソッド
   * https://openweathermap.org/api/one-call-api
   */
  public function bringInTheLaundry(Request $request)
  {
    // botインスタンス生成
    $httpClient = new CurlHTTPClient(env('LINE_ACCESS_TOKEN'));
    $lineBot = new LINEBot($httpClient, ['channelSecret' => env('LINE_CHANNEL_SECRET')]);

    $client = new Client();

    // 署名チェック
    // セキュリティのため署名を確認
    $signature = $request->header('x-line-signature');
    if (!$lineBot->validateSignature($request->getContent(), $signature)) {
        abort(400, 'Invalid signature');
    }
    $events = $lineBot->parseEventRequest($request->getContent(), $signature);

    // メッセージから緯度経度取得
    foreach($events as $event){
      if (!($event instanceof LocationMessage)) {
        continue;
      }
  
      $replyToken = $event->getReplyToken();
      $address = $event->getAddress();
      $lat = $event->getLatitude();
      $lon = $event->getLatitude();

      $response = $client
        ->get('https://api.openweathermap.org/data/2.5/onecall',[
          'query' => [
            'lat' => $lat,
            'lon' => $lon,
            'appid' => '1ad5efe09723bad6e6c9238084d91d7c',
            'lang' => 'ja',
            'exclude' => 'current,minutely,daily,alerts',
          ],
        ]);

      // jsonにデコードして情報を取得(これやらないとエラーになる)
      $decode = json_decode($response->getBody()->getContents(), true);
      $items = $decode['hourly'];

      // 12時間分の情報だけ確認
      $result = '〇';
      $information = '';
      for($i=0; $i<=12; $i++){
        $weather = $items[$i]['weather'][0];
        $date_time = date('m/d H:i', $items[$i]['dt']);
        $description = $weather['description'];
        $code = substr($weather['id'], 0, 1);

        if($code === '2' or $code === '5'){
          \Log::debug('success');
          $result = '×';
        }
        
        if($result !== '×' and $code === '3'){
          $result = '△';
        }

        $information .= $date_time. '　' .$description . "\n";
      }

      $replyContent = $address . "\n" . '洗濯もの'. ' '. $result. "\n" . $information;

      $lineBot->replyText($replyToken, $replyContent);
    }
  }
}
