<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\OrderRequest;
// use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\Cart;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\FlashSaleProduct;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderConfirmationMail;



class OrderController extends Controller

{
        public function checkout(OrderRequest $request)
    {
        $user_id = $request->input('user_id', null);
        $orderData = $request->only([
            'invoice_code', 'full_name', 'phone', 'email', 'total',
            'province', 'district', 'ward', 'address', 'payment_transpot', 'payment_method'
        ]);
        // $token = $user_id ? null : Str::random(64); // Tạo token nếu chưa đăng nhập
        $paymentStatus = 'unpaid';

        // Lấy danh sách sản phẩm từ yêu cầu
        $products = $request->input('product_variant_id', []);

        // Kiểm tra nếu giỏ hàng trống
        if (empty($products)) {
            return response()->json([
                'message' => 'Giỏ hàng trống hoặc sản phẩm không hợp lệ'
            ], 400);
        }

        // Tạo đơn hàng
        $order = Order::create(array_merge($orderData, [
            'user_id' => $user_id,
            'status' => 'pending',
            'payment_status' => $paymentStatus,
            'is_read' => false,
            // 'token' => $token,
        ]));


        if($orderData['payment_method'] == 1){
            // Gửi mail xác nhận đơn hàng
            // Mail::to($orderData['email'])->send(new OrderConfirmationMail($order));
        }

        // Lặp qua từng sản phẩm và cập nhật kho
        foreach ($products as $item) {
            $productVariant = ProductVariant::find($item['id']);
            if ($productVariant) {
                $quantity = $item['quantity'];
                $finalPrice = $productVariant->price;  // Mặc định là giá thông thường

                // Kiểm tra xem sản phẩm có đang trong chương trình flashsale không
                $flashSaleProduct = FlashSaleProduct::where('product_variant_id', $productVariant->id)->first();

                if ($flashSaleProduct) {

                    if ($flashSaleProduct->stock >= $quantity && $productVariant->stock >= $quantity) {
                        $discountedPrice = $productVariant->price * (1 - $flashSaleProduct->discount_percent / 100);
                        $finalPrice = round($discountedPrice, 2);

                        // Cập nhật stock
                        $flashSaleProduct->stock -= $quantity;
                        $flashSaleProduct->sold += $quantity;
                        $flashSaleProduct->save();

                        $productVariant->stock -= $quantity;
                        $productVariant->save();
                    } else {
                        // Không đủ stock trong flashsale, sử dụng discount product_variant
                        if ($productVariant->stock >= $quantity) {
                            $discountedPrice = $productVariant->price * (1 - $productVariant->discount / 100);
                            $finalPrice = round($discountedPrice, 2);

                            // Cập nhật stock
                            $productVariant->stock -= $quantity;
                            $productVariant->save();
                        } else {
                            return response()->json([
                                'message' => 'Số lượng sản phẩm không đủ trong kho'
                            ], 400);
                        }
                    }
                } else {
                    // Không phải flashsale, thì sử dụng bình thường
                    if ($productVariant->stock >= $quantity) {
                        // Áp dụng giảm giá cho biến thể sản phẩm nếu có
                        $discountedPrice = $productVariant->price * (1 - $productVariant->discount / 100);
                        $finalPrice = round($discountedPrice, 2);

                        $productVariant->stock -= $quantity;
                        $productVariant->save();
                    } else {
                        return response()->json([
                            'message' => 'Số lượng sản phẩm không đủ trong kho'
                        ], 400);
                    }
                }

                // Tạo đơn hàng
                OrderDetail::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $productVariant->id,
                    'quantity' => $quantity,
                    'price' => $finalPrice,
                ]);
            }
        }

        // Xóa sản phẩm trong giỏ hàng nếu là người dùng đã đăng nhập
        if ($user_id) {
            Cart::where('user_id', $user_id)
                ->whereIn('product_variant_id', array_column($products, 'id'))
                ->delete();
        }

        return response()->json([
            'message' => 'Order created successfully!',
            'order' => $order,
        ], 201);
    }



    public function showOrder($userId){

        if($userId){
            // Lấy danh sách đơn hàng của người dùng đã đăng nhập
        $orders = Order::where('user_id', $userId)->get();

        return response()->json($orders);
        }

        return response()->json([
            'message' => 'Không có đơn hàng'
        ]);

    }

    public function showOrderdetail($order){

        if($order){
            $order = Order::with(['payment', 'orderDetails','user'])->where('id', $order)->first();
            return response()->json([
                'order' => $order
            ]);
        }
        return response()->json([
            'message' => 'Không có đơn hàng'
        ]);

    }

    public function showOrderdetailcode($ordercode)
{
    if ($ordercode) {
        $order = Order::with([
            'payment',
            'orderDetails.productVariant' => function ($query) {
                $query->select('id', 'name', 'image'); // Chỉ lấy các trường cần thiết
            },
            'user'
        ])->where('invoice_code', $ordercode)->first();

        if ($order) {
            return response()->json([
                'order' => $order
            ]);
        }
    }

}


    public function show(){
        $orders = Order::orderBy('id', 'DESC')->get();
        return response()->json($orders);
    }

    public function updateOrderStatus(Request $request, $orderId)
{
    $order = Order::find($orderId);
    $newStatus = $request->input('status');

    // Định nghĩa các chuyển tiếp trạng thái hợp lệ
    $validStatuses = ['pending', 'preparing', 'transport', 'complete', 'canceled'];

    // Lấy chỉ số của trạng thái hiện tại và trạng thái mới
    $currentStatusIndex = array_search($order->status, $validStatuses);
    $newStatusIndex = array_search($newStatus, $validStatuses);

    // Kiểm tra nếu đơn hàng đã hủy
    if ($order->status === 'canceled') {
        return response()->json(['error' => 'Không thể cập nhật trạng thái đơn hàng đã hủy'], 400);
    }

    // Kiểm tra nếu trạng thái mới hợp lệ
    if ($newStatusIndex === false) {
        return response()->json(['error' => 'Trạng thái không hợp lệ'], 400);
    }

    // Kiểm tra nếu trạng thái mới nhỏ hơn trạng thái hiện tại (quay lại)
    if ($newStatusIndex < $currentStatusIndex) {
        return response()->json(['error' => 'Không thể quay lại trạng thái cũ'], 400);
    }

    // Kiểm tra nếu trạng thái mới giống với trạng thái hiện tại
    if ($newStatusIndex === $currentStatusIndex) {
        return response()->json(['error' => 'Đơn hàng đã ở trạng thái này'], 400);
    }

    $order->status = $newStatus;

    // Nếu trạng thái là 'complete', cộng điểm thưởng và đánh dấu thanh toán
    if ($newStatus === 'complete') {
        $order->payment_status = 'paid';

        if ($order->user_id) {
            $user = User::find($order->user_id);
            if ($user) {
                $loyaltyPoints = $order->total * 0.01; // 1% tổng tiền
                $user->point += $loyaltyPoints;
                $user->save();
            }
        }
    }

    $order->save();

    return response()->json(['message' => 'Cập nhật trạng thái thành công'], 200);
}

public function cancelOrder($orderId)
{
    $order = Order::find($orderId);

    // Kiểm tra nếu đơn hàng tồn tại và có thể hủy
    if (!$order) {
        return response()->json(['error' => 'Đơn hàng không tồn tại'], 404);
    }

    if (in_array($order->status, ['pending', 'preparing'])) {
        // Hoàn lại stock khi hủy
        if (!$order->refunded_stock) {
            foreach ($order->orderDetails as $item) {
                $productVariant = ProductVariant::find($item->product_variant_id);
                if ($productVariant) {
                    $productVariant->stock += $item->quantity;
                    $productVariant->save();
                }
            }
            $order->refunded_stock = true; // xác nhận đã hoàn stock
        }

        // Cập nhật trạng thái đơn hàng
        $order->status = 'canceled';
        $order->save(); // Lưu thay đổi vào cơ sở dữ liệu

        return response()->json(['message' => 'Đơn hàng đã được hủy thành công và hoàn lại stock'], 200);
    }

    return response()->json(['error' => 'Không thể hủy đơn hàng ở trạng thái hiện tại'], 400);
}


public function reorder(Request $request, $orderId)
{
    $user_id = $request->input('user_id', null);
    $order = Order::find($orderId);

    // Kiểm tra xem đơn hàng đã bị hủy hay chưa
    if ($order->status !== 'canceled') {
        return response()->json(['error' => 'Chỉ có thể tạo lại từ đơn hàng đã hủy'], 400);
    }

    // Cập nhật trạng thái đơn hàng từ 'canceled' về 'pending'
    $order->status = 'pending';
    $order->save(); // Lưu thay đổi vào cơ sở dữ liệu

    return response()->json(['message' => 'Đơn hàng đã được tạo lại từ đơn hàng đã hủy'], 201);
}

public function deductPoints(Request $request)
{
    $user_id = $request->input('user_id');
    $usedPoints = $request->input('used_points', 0); // Nhập điểm tích mà người dùng đã dùng

    $user = User::find($user_id);
    if (!$user) {
        return response()->json(['message' => 'User not found'], 404);
    }

    // Kiểm tra xem người dùng có đủ điểm tích lũy không
    if ($user->point < $usedPoints) {
        return response()->json(['message' => 'Không đủ điểm tích lũy'], 400);
    }

    // Trừ điểm và lưu lại
    $user->point -= $usedPoints;
    $user->save();

    return response()->json([
        'message' => 'Điểm đã được trừ thành công!',
        'points_used' => $usedPoints,
        'remaining_points' => $user->point
    ], 200);
}

    // Đánh dấu thông báo là đã đọc
    public function markAsRead($order)
    {
        $notification = Order::find($order);
        if (!$notification) {
            return response()->json(['message' => 'Thông báo không tồn tại'], 404);
        }

        $notification->is_read = true;
        $notification->save();

        return response()->json(['message' => 'Đã đánh dấu thông báo là đã đọc'], 200);
    }

    // Đếm số lượng thông báo chưa đọc
    public function countUnread()
    {
        $unreadCount = Order::where('is_read', false)->count();

        return response()->json(['notify' => $unreadCount], 200);
    }

    // Xem thông báo
    public function watchnotify()
{
    $view = Order::select('invoice_code', 'created_at') // Chỉ lấy các trường cần thiết
                 ->orderBy("id", "DESC")
                 ->get();

    if(!$view){
        return response()->json(['message' => 'Không có thông báo !']);
    }

    return response()->json($view, 200);
}


}
