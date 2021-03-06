<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidRequestException;
use App\Models\Order;
use Carbon\Carbon;
use Endroid\QrCode\QrCode;
use Illuminate\Http\Request;
use App\Events\OrderPaid;

class PaymentController extends Controller
{
    /**
     * 支付宝支付
     *
     * @param Order $order
     * @param Request $request
     * @return mixed
     * @throws InvalidRequestException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function payByAliPay(Order $order, Request $request)
    {
        // 判断订单是否属于当前用户
        $this->authorize('own', $order);
        // 订单已支付或已关闭
        if ($order->paid_at || $order->closed) {
            throw new InvalidRequestException('订单状态不正确');
        }

        // 调用支付宝的网页支付
        return app('alipay')->web(
            [
                'out_trade_no' => $order->no,       // 订单编号，需保证在商户端不重复
                'total_amount' => $order->total_amount,     // 订单金额，单位元，支持小数后两位
                'subject' => '支付涂呀商城的订单：'.$order->no,     // 订单标题
            ]
        );
    }

    /**
     * 支付宝前端回调页面
     */
    public function alipayReturn()
    {
        try {
            app('alipay')->verify();
        } catch (\Exception $exception) {
            return view('pages.error', ['message' => '数据不正确']);
        }

        return view('pages.success', ['message' => '付款成功']);

    }

    /**
     * 支付宝服务器端回调
     */
    public function alipayNotify()
    {
        // 校验输入参数
        $data = app('alipay')->verify();
        // 如果订单状态不是成功或者结束，则不走后续逻辑
        // 所有交易状态 https://docs.open.alipay.com/59/103672
        if (!in_array($data->trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            return app('alipay')->success();
        }
        // $data->out_trade_no 拿到订单流水号，并在数据库中查询
        $order = Order::query()->where('no', $data->out_trade_no)->first();
        // 正常来说不太可能出现支付了一笔不存在的订单，这里判断只是加强系统健壮性。
        if (!$order) {
            return 'fail';
        }
        // 如果这笔订单的状态已经是已支付
        if ($order->paid_at) {
            // 返回数据给支付宝
            return app('alipay')->success();
        }

        $order->update(
            [
                'paid_at' => Carbon::now(), // 支付时间
                'payment_method' => 'alipay', // 支付方式
                'payment_no' => $data->trade_no,    // 支付宝订单号
            ]
        );

        $this->afterPaid($order);

        return app('alipay')->success();
    }

    /**
     * 微信支付
     *
     * @param Order $order
     * @param Request $request
     * @return mixed
     * @throws InvalidRequestException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function payByWechatPay(Order $order, Request $request)
    {
        // 校验权限
        $this->authorize('own', $order);
        // 校验订单状态
        if ($order->paid_at || $order->closed) {
            throw new InvalidRequestException('订单状态不正确');
        }

        // scan 方法为拉起微信扫码支付
        $wechatOrder = app('wechatpay')->scan(
            [
                'out_trade_no' => $order->no, // 商户订单流水号，与支付宝 out_trade_no 一样
                'total_fee' => $order->total_amount * 100, // 与支付宝不同，微信支付的金额单位是分。
                'body' => '支付涂呀商城的订单'.$order->no, // 订单描述
            ]
        );
        // 把要转换的字符串作为 QrCode 的构造函数参数
        $qrCode = new QrCode($wechatOrder->code_url);

        // 将生成的二维码图片数据以字符串形式输出，并带上相应的响应类型
        return response($qrCode->writeString(), 200, ['Content-Type' => $qrCode->getContentType()]);
    }

    /**
     * 微信服务器端回调
     *
     * @return string
     */
    public function wechatNotify()
    {
        // 校验回调参数是否正确
        $data = app('wechatpay')->verify();
        // 找到对应的订单
        $order = Order::query()->where('no', $data->out_trade_no)->first();
        // 订单不存在则告知微信支付
        if (!$order) {
            return 'fail';
        }

        // 如果这笔订单的状态已经是已支付
        if ($order->paid_at) {
            // 返回数据给支付宝
            return app('wechatpay')->success();
        }

        $order->update(
            [
                'paid_at' => Carbon::now(), // 支付时间
                'payment_method' => 'wechatpay', // 支付方式
                'payment_no' => $data->transaction_id,    // 微信交易订单号
            ]
        );
        $this->afterPaid($order);

        return app('wechatpay')->success();
    }

    /**
     * 退款回调
     *
     * @param Request $request
     * @return string
     */
    public function wechatRefundNotify(Request $request)
    {
        // 给微信的失败响应
        $failXml = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[FAIL]]></return_msg></xml>';
        $data = app('wechatpay')->verify(null, true);
        // 没有找到对应的订单，原则上可能发生，保证代码健壮性
        if (!$order = Order::query()->where('no', $data['out_trade_no'])->first()) {
            return $failXml;
        }
        if ($data['refund_status'] === 'SUCCESS') {
            // 退款成功，将订单退款状态改成退出成功
            $order->update(
                [
                    'refund_status' => Order::REFUND_STATUS_SUCCESS,
                ]
            );
        } else {
            // 退款失败，将具体状态存入 extra 字段，并将退款状态改成失败
            $extra = $order->extra;
            $extra['refund_failed_code'] = $data['refund_status'];
            $order->update(
                [
                    'refund_status' => Order::REFUND_STATUS_FAILED,
                    'extra' => $extra,
                ]
            );
        }

        return app('wechatpay')->success();
    }

    /**
     * 支付成功事件
     *
     * @param Order $order
     */
    protected function afterPaid(Order $order)
    {
        event(new OrderPaid($order));
    }
}
