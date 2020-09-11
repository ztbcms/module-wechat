<?php
/**
 * Created by PhpStorm.
 * User: zhlhuang
 * Date: 2020-09-11
 * Time: 16:23.
 */

namespace app\wechat\service;


use app\common\service\BaseService;
use app\wechat\model\WechatApplication;
use app\wechat\model\WechatWxpayOrder;
use app\wechat\model\WechatWxpayRefund;
use EasyWeChat\Factory;

class WxpayService extends BaseService
{
    protected $payment = null;
    protected $appId = null;

    /**
     * @return \EasyWeChat\Payment\Application|null
     */
    public function getPayment(): ?\EasyWeChat\Payment\Application
    {
        return $this->payment;
    }

    /**
     * @param \EasyWeChat\Payment\Application|null $payment
     */
    public function setPayment(?\EasyWeChat\Payment\Application $payment): void
    {
        $this->payment = $payment;
    }

    /**
     * WxpayService constructor.
     * @param $appId
     * @param bool $isSandbox
     * @throws \Exception
     */
    public function __construct($appId, $isSandbox = false)
    {
        $application = WechatApplication::where('app_id', $appId)
            ->findOrEmpty();
        if ($application->isEmpty()) {
            throw new \Exception('找不到该应用信息');
        }

        $certDir = runtime_path() . "wechat/cert/{$appId}/";
        if (!is_dir($certDir)) mkdir($certDir, 0755, true);
        $certPath = $certDir . "cert.pem";
        $keyPath = $certDir . "key.pem";
        if (!file_exists($certPath)) file_put_contents($certPath, $application->cert_path);
        if (!file_exists($keyPath)) file_put_contents($keyPath, $application->key_path);

        $config = [
            'app_id' => $application->app_id,
            'mch_id' => $application->mch_id,
            'key' => $application->mch_key,
            'sandbox' => $isSandbox,
            'cert_path' => $certPath, // XXX: 绝对路径！！！！
            'key_path' => $keyPath,      // XXX: 绝对路径！！！！
            // 下面为可选项
            // 指定 API 调用返回结果的类型：array(default)/collection/object/raw/自定义类名
            'response_type' => 'array',
            'log' => [
                'level' => 'debug',
                'file' => runtime_path() . 'wechat/wechat.log',
            ],
        ];
        $this->appId = $appId;
        $this->payment = Factory::payment($config);
    }

    /**
     * @param $openId
     * @param $outTradeNo
     * @param $totalFee
     * @param $notifyUrl
     * @param string $body
     * @param string $tradeType
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return bool|mixed
     */
    function createUnity($openId, $outTradeNo, $totalFee, $notifyUrl, $body = "微信支付", $tradeType = "JSAPI")
    {
        $result = $this->payment->order->unify([
            'body' => $body,
            'out_trade_no' => $outTradeNo,
            'total_fee' => $totalFee,
            'notify_url' => $notifyUrl, // 支付结果通知网址，如果不设置则会使用配置里的默认地址
            'trade_type' => $tradeType, // 请对应换成你的支付方式对应的值类型
            'openid' => $openId,
        ]);
        if ($result['result_code'] == 'SUCCESS' && $result['return_code'] == 'SUCCESS') {
            return $result['prepay_id'];
        }
        $this->setError($result['err_code_des']);
        return false;
    }

    /**
     * @param $openId
     * @param $outTradeNo
     * @param $totalFee
     * @param $notifyUrl
     * @param string $body
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return mixed
     */
    function getJssdkPayConfig($openId, $outTradeNo, $totalFee, $notifyUrl, $body = "微信支付")
    {
        $prepayId = $this->createUnity($openId, $outTradeNo, $totalFee, $notifyUrl, $body, "JSAPI");
        if (!$prepayId) {
            $this->setError('微信支付下单失败：' . $this->getError());
            return false;
        }
        //添加支付订单入库
        $wxpayOrderModel = new WechatWxpayOrder();
        $wxpayOrderModel->app_id = $this->appId;
        $wxpayOrderModel->open_id = $openId;
        $wxpayOrderModel->out_trade_no = $outTradeNo;
        $wxpayOrderModel->total_fee = $totalFee;
        $wxpayOrderModel->create_time = time();
        $wxpayOrderModel->notify_url = $notifyUrl;
        $wxpayOrderModel->save();
        $res = $this->payment->jssdk->sdkConfig($prepayId);
        return $res;
    }

    /**
     * @param $func
     * @throws \EasyWeChat\Kernel\Exceptions\Exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    function handlePaidNotify($func)
    {
        return $this->payment->handlePaidNotify(function ($message, $fail) use ($func) {
            $outTradeNo = $message['out_trade_no'];
            $wxpayOrderModel = WechatWxpayOrder::where('out_trade_no', $outTradeNo)->findOrEmpty();
            if (!$wxpayOrderModel->isEmpty()) {
                $wxpayOrderModel->mch_id = $message['mch_id'];
                $wxpayOrderModel->nonce_str = $message['nonce_str'];
                $wxpayOrderModel->sign = $message['sign'];
                $wxpayOrderModel->result_code = $message['result_code'];
                $wxpayOrderModel->mch_id = $message['mch_id'];
                $wxpayOrderModel->err_code = empty($message['err_code']) ? '' : $message['err_code'];
                $wxpayOrderModel->err_code_des = empty($message['err_code_des']) ? '' : $message['err_code_des'];
                $wxpayOrderModel->open_id = $message['openid'];
                $wxpayOrderModel->is_subscribe = $message['is_subscribe'];
                $wxpayOrderModel->trade_type = $message['trade_type'];
                $wxpayOrderModel->bank_type = $message['bank_type'];
                $wxpayOrderModel->total_fee = $message['total_fee'];
                $wxpayOrderModel->cash_fee = $message['cash_fee'];
                $wxpayOrderModel->transaction_id = $message['transaction_id'];
                $wxpayOrderModel->out_trade_no = $message['out_trade_no'];
                $wxpayOrderModel->time_end = $message['time_end'];

                $wxpayOrderModel->save();
                $func($message, $fail);
            } else {
                $fail('订单不存在');
            }
        });
    }

    /**
     * @param $outTradeNo
     * @param $totalFee
     * @param $refundFee
     * @param $refundDescription
     * @return bool
     */
    function createRefund($outTradeNo, $totalFee, $refundFee, $refundDescription)
    {
        $outRefundNo = date("YmdHis") . rand(100000, 999990);
        $wxpayRefundModel = new WechatWxpayRefund();
        $wxpayRefundModel->app_id = $this->appId;
        $wxpayRefundModel->out_trade_no = $outTradeNo;
        $wxpayRefundModel->out_refund_no = $outRefundNo;
        $wxpayRefundModel->total_fee = $totalFee;
        $wxpayRefundModel->refund_fee = $refundFee;
        $wxpayRefundModel->refund_description = $refundDescription;
        $wxpayRefundModel->status = WechatWxpayRefund::STATUS_NO;
        $wxpayRefundModel->next_process_time = time();
        $wxpayRefundModel->process_count = 0;

        if ($wxpayRefundModel->save()) {
            return true;
        } else {
            $this->setError('申请退款失败，请联系管理员');
            return false;
        }
    }

    /**
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @return mixed
     */
    function doRefundOrder()
    {
        $wxpayRefundModel = new WechatWxpayRefund();
        $where = [
            ['app_id', '=', $this->appId],
            ['status', '=', WechatWxpayRefund::STATUS_NO], //处理未完成的退款
            ['next_process_time', '<', time()],//处理时间小于现在时间
            ['process_count', '<', 7],//处理次数小于7次
        ];
        $refundOrders = $wxpayRefundModel->where($where)->select();
        $nextProcessTimeArray = [60, 300, 900, 3600, 10800, 21600, 86400];
        foreach ($refundOrders as $refundOrder) {
            try {
                $refundRes = $this->payment->refund->byOutTradeNumber($refundOrder->out_trade_no, $refundOrder->out_refund_no, $refundOrder->total_fee, $refundOrder->refund_fee, [
                    'refund_desc' => $refundOrder->refund_description ? $refundOrder->refund_description : '无',
                ]);
                if ($refundRes['result_code'] == 'SUCCESS' && $refundRes['return_code'] == 'SUCCESS') {
                    $postData = [
                        'status' => WechatWxpayRefund::STATUS_YES,
                        'refund_result' => json_encode($refundRes),
                        'next_process_time' => time() + (empty($nextProcessTimeArray[$refundOrder->process_count]) ? 86400 : $nextProcessTimeArray[$refundOrder->process_count]),
                        'process_count' => $refundOrder['process_count'] + 1,
                        'update_time' => time()
                    ];
                    WechatWxpayRefund::where('id', $refundOrder->id)->update($postData);
                } else {
                    $postData = [
                        'status' => WechatWxpayRefund::STATUS_NO,
                        'refund_result' => json_encode($refundRes),
                        'next_process_time' => time() + (empty($nextProcessTimeArray[$refundOrder['process_count']]) ? 86400 : $nextProcessTimeArray[$refundOrder->process_count]),
                        'process_count' => $refundOrder->process_count + 1,
                        'update_time' => time()
                    ];
                    WechatWxpayRefund::where('id', $refundOrder->id)->update($postData);
                }
            } catch (\EasyWeChat\Kernel\Exceptions\Exception $exception) {
                $postData = [
                    'status' => WechatWxpayRefund::STATUS_NO,
                    'refund_result' => $exception->getMessage(),
                    'next_process_time' => time() + (empty($nextProcessTimeArray[$refundOrder->process_count]) ? 86400 : $nextProcessTimeArray[$refundOrder->process_count]),
                    'process_count' => $refundOrder['process_count'] + 1,
                    'update_time' => time()
                ];
                WechatWxpayRefund::where('id', $refundOrder->id)->update($postData);
            }
        }
        return true;
    }
}