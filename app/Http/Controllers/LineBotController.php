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
      $lon = $event->getLongitude();
      
      $response = $client
        ->get('https://api.openweathermap.org/data/2.5/onecall',[
          'query' => [
            'lat' => $lat,
            'lon' => $lon,
            'appid' => env('WEATHER_API_KEY'),
            'lang' => 'ja',
            'exclude' => 'current,minutely,daily,alerts',
            'units' => 'metric',
          ],
        ]);

      // jsonにデコードして情報を取得(これやらないとエラーになる)
      $decode = json_decode($response->getBody()->getContents(), true);
      $items = $decode['hourly'];

      // 12時間分の情報だけ確認
      $laundry_result = '〇';
      $umbrella_result = '×';
      $rain_info = null;
      $information = '';
      for($i=0; $i<=12; $i++){
        $weather = $items[$i]['weather'][0];
        $date_time = date('m/d H:i', $items[$i]['dt']);
        $description = $weather['description'];
        $feel_like = $items[$i]['feels_like'];
        
        // 洗濯物の判定
        $code = substr($weather['id'], 0, 1);
        $laundry_result = $this->judgeLaundry($code, $laundry_result);

        // 傘の判定
        if(array_key_exists('rain', $items[$i])){
          $rain = $items[$i]['rain']['1h'];
          list(
            $umbrella_result,
            $rain_description,
          ) = $this->judgeUmbrella($rain, $umbrella_result);
          $rain_info = $rain. 'mm/h'. $rain_description;
        }else{
          $rain_info = 'なし';
        }

        $information .= '▼'. $date_time. "\n" 
          . '予報：'. $description. "\n" 
          . '降水量：'. $rain_info. "\n"
          .'体感温度：' . $feel_like. '℃'. "\n". "\n";
      }

      $laundry = '洗濯物：'. $laundry_result;
      $umbrella = '傘：'. $umbrella_result;

      $replyContent = $address."\n". "\n". $laundry."\n". $umbrella. "\n". "\n". $information;

      $lineBot->replyText($replyToken, $replyContent);
    }
  }

  public function judgeLaundry(string $code, string $laundry_result)
  {
    if($code === '2' or $code === '5'){
      $laundry_result = '×';
    }
    
    if($laundry_result !== '×' and $code === '3'){
      $laundry_result = '△';
    }

    return $laundry_result;
  }

  public function judgeUmbrella(int $rain, string $umbrella_result)
  {
    $rain_description = '';

    if($rain > 0 && $rain < 5){
      $umbrella_result = '△';
      $rain_description = '(ポツポツ)';
    }

    if($rain >= 5 && $rain < 20 ){
      $umbrella_result = '〇';
      $rain_description = '(本降り)';
    }

    if($rain >= 20){
      $umbrella_result = '◎';
      $rain_description = '(土砂降り)';
    }

    return [$umbrella_result, $rain_description];
  }
}
