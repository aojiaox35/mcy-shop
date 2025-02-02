<?php
declare (strict_types=1);

namespace App\Controller\Admin\API\Pay;

use App\Controller\Admin\Base;
use App\Entity\Query\Get;
use App\Interceptor\Admin;
use App\Interceptor\PostDecrypt;
use App\Model\PayOrder;
use App\Model\PayOrder as Model;
use App\Service\Common\Query;
use App\Validator\Common;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Relations\HasOne;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Validator;
use Kernel\Context\Interface\Response;
use Kernel\Database\Db;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Date;
use Kernel\Waf\Filter;

#[Interceptor(class: [PostDecrypt::class, Admin::class], type: Interceptor::API)]
class Order extends Base
{
    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\User\Order $order;


    /**
     * @return Response
     * @throws RuntimeException
     */
    #[Validator([
        [Common::class, ["page", "limit"]]
    ])]
    public function get(): Response
    {
        $map = $this->request->post();
        $get = new Get(Model::class);
        $get->setWhere($map);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy("id", "desc");
        $get->setWhereLeftJoin(\App\Model\Order::class, "id", "order_id", ["trade_no" => "trade_no"]);
        $row = [];
        $data = $this->query->get($get, function (Builder $builder) use ($map, &$row) {
            if (isset($map['display_scope'])) {
                if ($map['display_scope'] == 1) {
                    $builder = $builder->whereNull("pay_order.user_id");
                } elseif ($map['display_scope'] == 2) {
                    if (isset($map['user_id']) && $map['user_id'] > 0) {
                        $builder = $builder->where("pay_order.user_id", $map['user_id']);
                    } else {
                        $builder = $builder->whereNotNull("pay_order.user_id");
                    }
                }
            }

            $row['order_count'] = (clone $builder)->count();
            $row['trade_amount'] = (clone $builder)->sum("trade_amount"); //第三方支付金额
            $row['balance_amount'] = (clone $builder)->sum("balance_amount"); //余额支付金额

            return $builder->with([
                "customer" => function (HasOne $one) {
                    $one->select(["id", "username", "avatar"]);
                },
                "user" => function (HasOne $one) {
                    $one->select(["id", "username", "avatar"]);
                },
                "pay" => function (HasOne $one) {
                    $one->with(['user', 'parent'])->select(["id", "name", "icon", "user_id", "pid"]);
                },
                "order" => function (HasOne $one) {
                    $one->select(["id", "trade_no", "create_ip", "create_browser", "create_device"]);
                }
            ]);
        });
        return $this->json(data: array_merge($data, $row));
    }


    /**
     * @return Response
     * @throws RuntimeException
     */
    public function getLatestOrderId(): Response
    {
        $p = PayOrder::query()->whereNull("user_id")->orderBy("id", "desc")->first();
        return $this->json(data: ["id" => $p ? $p->id : 0]);
    }

    /**
     * @return Response
     * @throws RuntimeException
     */
    public function status(): Response
    {
        $list = (array)$this->request->post("list", Filter::INTEGER);

        if (count($list) == 0) {
            return $this->json(data: ["status" => false]);
        }

        $payOrders = PayOrder::query()->whereIn("id", $list)->get();

        foreach ($payOrders as $payOrder) {
            if ($payOrder->status == 2 || $payOrder->status == 3) {
                return $this->json(data: ["status" => true]);
            }
        }

        return $this->json(data: ["status" => false]);
    }


    /**
     * @return Response
     * @throws \Throwable
     */
    #[Validator([
        [Common::class, "id"]
    ])]
    public function successful(): Response
    {
        $id = $this->request->post("id", Filter::INTEGER);

        Db::transaction(function () use ($id) {

            /**
             * @var PayOrder $payOrder
             */
            $payOrder = PayOrder::find($id);


            if (!$payOrder || ($payOrder->status != 1 && $payOrder->status != 3)) {
                throw new JSONException("此订单无法操作#0");
            }

            /**
             * @var \App\Model\Order $order
             */
            $order = \App\Model\Order::query()->find($payOrder->order_id);

            if (!$order || $order->status != 3) {
                throw new JSONException("此订单无法操作#1");
            }

            $this->order->deliver($order, $this->request->clientIp());
            $payOrder->status = 2;
            $payOrder->pay_time = Date::current();
            $payOrder->save();
        }, \Kernel\Database\Const\Db::ISOLATION_SERIALIZABLE);

        return $this->json();
    }

    /**
     * @return Response
     * @throws JSONException
     * @throws RuntimeException
     */
    #[Validator([
        [Common::class, "id"]
    ])]
    public function close(): Response
    {
        $id = $this->request->post("id", Filter::INTEGER);
        /**
         * @var PayOrder $payOrder
         */
        $payOrder = PayOrder::query()->find($id);
        if (!$payOrder) {
            throw new JSONException("订单不存在");
        }

        if ($payOrder->status != 0 && $payOrder->status != 1) {
            throw new JSONException("该订单状态无法操作");
        }

        $payOrder->status = 3;
        $payOrder->save();
        return $this->json();
    }
}