# 店小秘 → PayPal Package Tracking 桥接版

## 这版和旧插件的区别

这不是第二个 PayPal API 客户端。它只负责把店小秘写入 WooCommerce 的物流数据交给 **WooCommerce PayPal Payments 自带的 PayPal Package Tracking**。

- 已按你提供的 WooCommerce PayPal Payments **4.1.1** 代码适配。
- 复用 PayPal Payments 当前已连接的商户、OAuth Token、PayPal Order ID 和 Capture ID。
- 不需要另外填写 Client ID 或 Secret。
- 不改 WooCommerce PayPal Payments 原插件文件，更新 PayPal 插件时不会覆盖本桥接插件。
- 不拦截、不替换店小秘或 WooCommerce 的发货邮件。
- 支持 HPOS、Action Scheduler、重复提交保护和失败重试。

## 安装前

必须先停用旧的运单回传插件，避免同一运单被提交两次：

1. `Add Tracking Info to PayPal` 1.2.0（原付费插件）
2. `Dianxiaomi to PayPal Tracking Sync` 2.x（独立 Client ID/Secret 版本，如曾安装）

不要停用 `WooCommerce PayPal Payments`。

## 安装

1. WordPress 后台进入“插件 → 安装插件 → 上传插件”。
2. 上传本 ZIP，安装并启用。
3. 进入“WooCommerce → PayPal 运单桥接”。
4. 确认页面显示：
   - WooCommerce PayPal Payments：`4.1.1`
   - 官方 Package Tracking API：`可用`
   - 重复同步插件：`未检测到`
5. 默认字段无需修改：
   - 运单号：`_dianxiaomi_tracking_number`
   - 承运商：`_dianxiaomi_tracking_provider`
   - 承运商名称：`_dianxiaomi_tracking_provider_name`

## 第一次上线测试

请先只用一笔真实、金额较小且已经发货的 PayPal 订单测试：

1. 在“订单诊断与手动同步”输入 WooCommerce 订单 ID。
2. 确认页面读到了：
   - PayPal Order ID
   - Capture ID
   - 店小秘真实运单号
   - 正确的 PayPal 承运商代码，例如 `3PE_EXPRESS`
3. 点击“强制提交/更新到 PayPal”。
4. 页面出现绿色“同步成功”并显示最后成功时间后，返回该订单并刷新右侧的 `PayPal Package Tracking` 区块。
5. 确认运单已经出现，再去 PayPal 后台核对。

美国等非中国商店中，YunExpress 可能显示为 `OTHER / yunexpress`。这是因为 PayPal Payments 4.1.1 把 `YUNEXPRESS` 放在中国商户承运商列表；桥接插件遵循当前商店原生下拉框，使用 PayPal 支持的自定义承运商方式提交，不代表失败。

不要使用假运单号测试真实订单，因为 PayPal 和买家可能会看到该数据。

## 自动工作方式

订单必须已经进入 **Completed（完成/已发货）**。满足这个前提后，以下任一情况发生时，插件会延迟约 20 秒提交：

- 店小秘写入或更新运单号/承运商字段；
- 店小秘通过 REST API 添加包含物流单号的订单备注；
- 插件启用前已经存在的客户/内部发货备注（回扫最近 100 条）；
- 订单进入 Completed；
- HPOS 订单对象或 WooCommerce REST 订单被保存；
- 标准 `_wc_shipment_tracking_items` 出现新运单。

相同订单、相同运单号、相同承运商只提交一次。承运商发生变化时会调用 PayPal Payments 的更新逻辑。临时网络错误最多自动重试 5 次。

## 往期订单批量同步

进入“WooCommerce → PayPal 运单桥接 → 往期订单批量同步”：

1. 可以选择最近 7 天、15 天或 30 天；范围按 WooCommerce 的订单完成时间（即本店发货时间）计算，不按下单时间计算。
2. 建议先点“同步最近 7 天已发货订单”，确认订单列表的状态正确后再扩大范围。
3. 每批只读取 40 笔 Completed 订单，并在后台继续下一批，不需要保持页面打开；Processing 完全不会进入同步。
4. 只有同时具有 PayPal Order ID、Capture ID 和真实运单号的订单才会排队。
5. 已同步、已排队、非 PayPal 和无运单号的订单都会跳过，避免重复提交。

“扫描完成”表示符合条件的订单已排入后台。最终成功或失败请在“WooCommerce → 订单”的“PayPal 运单”列查看。

## 订单页面入口

- 订单列表新增“PayPal 运单”状态列，显示“已同步、等待中、失败、未同步”等状态。
- 每一行可以点击“立即同步”，操作结束后会返回原来的页码、搜索和筛选条件；也可以勾选多个订单后使用批量操作“同步 PayPal 运单（店小秘）”。
- Processing 订单显示“未发货”，不会显示单笔同步入口，也不会被勾选批量操作提交。
- 单一订单详情页新增“PayPal 运单同步”区块，可以查看单号、上次成功时间和错误，并点击“立即提交/更新到 PayPal”。
- 原有订单操作下拉框中的“店小秘：提交/更新 PayPal 运单”仍然保留。

## Guest/Card 订单

是否能提交不取决于买家有没有登录 PayPal 账号，而取决于订单是否由 WooCommerce PayPal Payments 创建并且已经捕获。诊断页面同时显示 PayPal Order ID 和 Capture ID 时，桥接插件会处理 PayPal、Pay Later 以及该插件处理的 Guest/Card 订单。

尚未 Capture 的授权订单会等待捕获后再重试。

## 排查

1. 进入“WooCommerce → PayPal 运单桥接”，读取订单。
2. 查看“上次错误”和检测到的字段。
3. 开启“调试日志”。
4. 进入“WooCommerce → 状态 → 日志”，选择来源 `dxm-paypal-package-bridge`。

如果 PayPal 官方区块本身手动提交也失败，应先修复 WooCommerce PayPal Payments 的连接或商户权限；桥接插件不会绕过其认证。

## 停用和回滚

直接停用本桥接插件即可停止后续自动提交，不影响付款和已发送到 PayPal 的运单。插件不会删除订单、付款数据或 PayPal 配置。
