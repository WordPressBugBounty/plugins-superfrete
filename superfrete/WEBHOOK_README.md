# SuperFrete Webhooks Implementation

This document describes the complete SuperFrete webhooks system implemented in the WooCommerce plugin.

## 🎯 Overview

The webhook system automatically receives and processes order status updates from SuperFrete, providing real-time tracking information and automatic order status updates in WooCommerce.

## 🏗️ Architecture

### Core Components

1. **WebhookController** - Handles incoming webhook requests via WordPress REST API
2. **WebhookRetryManager** - Manages failed webhook processing with exponential backoff
3. **WebhookVerifier** - Validates webhook signatures using HMAC SHA-256
4. **WebhookAdmin** - Admin interface for monitoring webhook logs and retries
5. **Database Migrations** - Creates necessary database tables for logging and retries

### Database Tables

- `wp_superfrete_webhook_retries` - Queue for failed webhook processing
- `wp_superfrete_webhook_logs` - Complete webhook activity logs

## 🚀 Features

### ✅ Automatic Webhook Registration
- Attempts registration during plugin activation
- Manual registration via admin interface
- Supports both production and sandbox environments

### ✅ Signature Verification
- HMAC SHA-256 signature validation
- Prevents unauthorized webhook requests
- Secure webhook secret storage

### ✅ Event Processing
- **order.posted** - Updates order status to "Enviado" (Shipped)
- **order.delivered** - Updates order status to "Concluído" (Completed)
- Stores tracking codes, URLs, and timestamps

### ✅ Retry Mechanism
- Exponential backoff (5, 10, 20 minutes)
- Maximum 3 retry attempts
- Automatic cleanup of old retry records

### ✅ Comprehensive Logging
- All webhook events logged with timing information
- Error tracking and debugging information
- Admin interface for log viewing

### ✅ Admin Interface
- Webhook registration status
- Real-time statistics dashboard
- Manual retry processing
- Log cleanup tools

## 🔧 Configuration

### Webhook URL
The webhook endpoint is automatically generated:
```
https://your-site.com/wp-json/superfrete/v1/webhook
```

### Required Settings
1. SuperFrete API token (production or sandbox)
2. Webhook registration (handled automatically)
3. Webhook secret (auto-generated and stored securely)

## 📋 Installation & Setup

### Automatic Setup
1. Install/activate the plugin
2. Configure SuperFrete API token in WooCommerce settings
3. Webhooks are registered automatically

### Manual Setup
1. Go to WooCommerce → Settings → Shipping
2. Scroll to "Configuração de Webhooks"
3. Click "Registrar Agora" if webhooks aren't registered

## 📊 Monitoring

### Admin Dashboard
Access via: WooCommerce → SuperFrete Webhooks

**Statistics Available:**
- Total webhooks processed (last 30 days)
- Pending retry attempts
- Completed processing count
- Failed processing count

**Management Actions:**
- Manual retry processing
- Log cleanup
- Detailed webhook logs view

### Webhook Logs
Track all webhook activity including:
- Event type and payload
- Processing time
- Success/failure status
- Retry attempts

## 🔄 Order Status Flow

```
Order Created → SuperFrete API → Freight Generated
        ↓
SuperFrete Posts Package → order.posted webhook
        ↓
WooCommerce Status: "Enviado" + Tracking Info
        ↓
Package Delivered → order.delivered webhook
        ↓
WooCommerce Status: "Concluído"
```

## 🛠️ Troubleshooting

### Common Issues

**Webhooks Not Registered**
- Check API token validity
- Verify WordPress REST API is enabled
- Try manual registration from admin

**Webhook Processing Failures**
- Check webhook logs in admin
- Verify order exists in WooCommerce
- Check SuperFrete ID mapping

**Signature Verification Failures**
- Ensure webhook secret is properly stored
- Check for payload modification by proxies
- Verify HTTPS is working correctly

### Debug Information
Enable WP_DEBUG and check logs at:
- SuperFrete plugin logs
- WordPress error logs
- Webhook admin dashboard

## 🔐 Security

### Implemented Protections
- HMAC SHA-256 signature verification
- WordPress nonce validation for admin actions
- Capability checks for admin functions
- SQL injection prevention with prepared statements

### Best Practices
- Use HTTPS for webhook endpoint
- Regularly monitor webhook logs
- Keep plugin updated
- Backup webhook configuration

## 🚀 Performance

### Optimizations
- Efficient database queries with proper indexing
- Cron-based retry processing to avoid blocking
- Automatic cleanup of old logs and retry records
- Minimal processing time tracking

### Recommended Maintenance
- Monitor retry queue size
- Clean up old logs monthly
- Review failed webhook patterns
- Update retry limits if needed

## 🔄 API Integration

### SuperFrete Webhook Events
```json
{
  "event": "order.posted",
  "data": {
    "id": "superfrete_freight_id",
    "tracking": "tracking_code",
    "tracking_url": "https://tracking.url",
    "posted_at": "2024-01-01T10:00:00Z"
  }
}
```

### Webhook Registration API
```json
POST /api/v0/webhooks
{
  "url": "https://your-site.com/wp-json/superfrete/v1/webhook",
  "events": ["order.posted", "order.delivered"],
  "description": "WooCommerce SuperFrete Plugin Webhook"
}
```

## 📈 Future Enhancements

### Planned Features
- Real-time order tracking widget
- Email notifications for status changes
- Advanced retry configuration
- Webhook event filtering
- Performance analytics dashboard

### Integration Possibilities
- Integration with other shipping providers
- Custom order status creation
- Advanced tracking page
- Mobile app notifications

## 🤝 Contributing

To extend the webhook system:

1. **Add New Events**: Extend `WebhookController::process_webhook()`
2. **Custom Processing**: Create handlers in `WebhookController`
3. **Admin Features**: Extend `WebhookAdmin` class
4. **Database Changes**: Add migrations to `WebhookMigrations`

## 📝 Changelog

### Version 2.1.4+ (Webhook Implementation)
- ✅ Complete webhook system implementation
- ✅ Automatic order status updates
- ✅ Retry mechanism with exponential backoff
- ✅ Admin dashboard for monitoring
- ✅ Comprehensive logging system
- ✅ Security with HMAC verification
- ✅ Custom order status support

---

**Need Help?** Check the webhook logs in the admin dashboard or enable WordPress debug logging for detailed error information. 