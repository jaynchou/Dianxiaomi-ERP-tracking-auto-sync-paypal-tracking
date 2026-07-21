# Woocommerce店小秘ERP订单追踪信息自动同步PayPal追踪信息

自己用ai搓了一个 WooCommerce 插件：读取店小秘写入的运单号，通过 **WooCommerce PayPal Payments 自带的 Package Tracking 接口**回传给 PayPal，防止paypal没有填追踪信息触发风控。

## 主要功能

- 支持店小秘 API 订单备注、店小秘插件字段和 WooCommerce Shipment Tracking 字段。
- 仅同步 `Completed`（已完成发货）的订单，排除 `Processing`。
- 仅处理具有 PayPal Order ID、Capture ID 和真实运单号的订单。
- 支持最近 7 天、15 天、30 天以及全部往期已发货订单的后台同步。
- 支持 HPOS 和传统订单存储。
- 订单列表显示 PayPal 运单同步状态，并提供单笔及勾选批量同步。
- 单笔同步后保留当前订单列表页码、搜索和筛选条件。
- 通过签名防止同一个运单重复提交，并对临时 API 错误进行有限重试。

## 要求

- WordPress 6.5+
- PHP 7.4+
- WooCommerce 9.6+
- WooCommerce PayPal Payments 3.1.0+（已按 4.1.1 验证）
- 店小秘erp接口（api或者插件都可以）
## 安装

下载仓库 ZIP 后在 WordPress 后台进入“插件 → 安装插件 → 上传插件”，安装并启用，然后打开“WooCommerce → PayPal 运单桥接”。

首次使用请先选一笔金额较小、已经发货且已 Capture 的真实 PayPal 订单进行测试。详细说明见 [INSTALL-ZH.md](INSTALL-ZH.md)。

## 许可

GPL-3.0-or-later。
