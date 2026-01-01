# Warning System API Documentation

## Overview
The warning system API provides endpoints to manage and configure various types of warnings for farm monitoring and management. This comprehensive system allows farmers to set up automated alerts for critical farm operations, equipment status, environmental conditions, and pest management.

## System Architecture

The warning system is built on a flexible, parameterized architecture that supports multiple warning types and delivery channels:

### Core Components

1. **Warning Definitions** - Stored in `storage/app/json/warnings.json`, defining available warning types
2. **Warning Service** - Core business logic for warning management and validation
3. **Warning Model** - Database representation of user-configured warnings
4. **Notification System** - Multi-channel delivery (Database, Firebase, Email)
5. **Specialized Services** - Type-specific warning checking and notification services

### Warning Types

The system supports three main warning categories:

- **Condition-based**: Triggered by real-time conditions (tractor stoppage, inactivity)
- **Schedule-based**: Triggered by scheduled checks (frost warnings, degree day calculations)
- **One-time**: Event-driven notifications (irrigation start/end)

### Data Flow

1. **Configuration**: Users configure warnings via API endpoints
2. **Storage**: Settings stored in database with farm-specific parameters
3. **Monitoring**: Background services check conditions based on warning types
4. **Notification**: When conditions are met, notifications are sent via multiple channels
5. **Delivery**: Notifications delivered through Firebase, database, and email channels

## Authentication
All endpoints require authentication using Laravel Sanctum. Include your authentication token in the request header:
```
Authorization: Bearer your-token-here
```

## Endpoints

### List Warnings

Retrieves all warnings for a specific section of the farm management system.

**GET** `/api/v1/warnings`

#### Query Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| related-to | string | Yes | The section to get warnings for (e.g., "farm", "tractors", "irrigation", "pests", "crop_types") |

#### Response
```json
{
    "data": [
        {
            "key": "warning_key",
            "setting_message": "Formatted setting message",
            "enabled": boolean,
            "parameters": {
                "param_name": "value"
            },
            "setting_message_parameters": ["available_parameters"]
        }
    ]
}
```

#### Error Responses
- `400 Bad Request` - Missing related-to parameter
- `401 Unauthorized` - Invalid or missing authentication token

### Update Warning Settings

Creates or updates settings for a specific warning.

**POST** `/api/v1/warnings`

#### Request Body
```json
{
    "key": "warning_key",
    "enabled": boolean,
    "parameters": {
        "param_name": "value"
    },
    "type": "warning_type"
}
```

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | The unique identifier for the warning type (see Available Warning Types) |
| `enabled` | boolean | Yes | Whether the warning is active or disabled |
| `parameters` | object | Yes | Warning-specific configuration parameters (see detailed specifications below) |
| `type` | string | No | Warning execution type: `one-time`, `schedule-based`, or `condition-based` (auto-detected if not provided) |

#### Parameter Types and Validation

The `parameters` object contains warning-specific configuration. Each warning type has different required parameters based on its `setting-message-parameters` array defined in `warnings.json`.

**Common Parameter Types:**
- **Time-based**: `hours`, `days` - Integer values (≥ 1) representing duration
- **Date-based**: `start_date`, `end_date`, `date` - Jalali date strings in format `YYYY/MM/DD` (e.g., `1403/01/15`), automatically converted to Carbon instances
- **String-based**: `pest`, `crop_type` - Text identifiers (max 255 characters) for specific entities
- **Numeric**: `degree_days` - Numeric values (≥ 0) for degree day calculations

**Validation Rules:**
- All parameters listed in the warning's `setting-message-parameters` are **required**
- Only parameters listed in `setting-message-parameters` are **allowed** (extra parameters will be rejected)
- Parameter types are automatically validated based on parameter names
- Date parameters must be valid Jalali calendar dates (years 13xx or 14xx)

**Date Format**: All date parameters must be in Jalali date format (`YYYY/MM/DD`) and are automatically validated and converted to Carbon instances for processing.

#### Response
```json
{
    "message": "Warning settings updated successfully",
    "warning": {
        "id": number,
        "farm_id": number,
        "key": "string",
        "enabled": boolean,
        "parameters": {
            "param_name": "value"
        }
    }
}
```

#### Error Responses
- `401 Unauthorized` - Invalid or missing authentication token
- `422 Unprocessable Entity` - Invalid parameters or warning key

## Available Warning Types

### Tractor Management Warnings

#### `tractor_stoppage`
**Type**: `condition-based`  
**Related To**: `tractors`  
**Description**: Monitors tractor GPS data to detect when a tractor has been stationary for an extended period.

**Parameters:**
| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `hours` | integer | Yes | Maximum allowed stoppage duration in hours | `2` |

**Setting Message**: "Warn me if a tractor stops for more than :hours hours."  
**Warning Message**: "Tractor name :tractor_name has been stopped for more than :hours hours on :date. Please check the reason."

**Example Configuration:**
```json
{
    "key": "tractor_stoppage",
    "enabled": true,
    "parameters": {
        "hours": "3"
    }
}
```

**Use Case**: Detect equipment malfunctions or unauthorized stops during field operations.

---

#### `tractor_inactivity`
**Type**: `condition-based`  
**Related To**: `tractors`  
**Description**: Monitors tractor communication status to detect when no GPS data is received.

**Parameters:**
| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `days` | integer | Yes | Maximum allowed inactivity duration in days | `1` |

**Setting Message**: "Warn me if no data is received from a tractor for more than :days days."  
**Warning Message**: "Tractor name :tractor_name has been inactive for more than :days days. Please check the reason."

**Example Configuration:**
```json
{
    "key": "tractor_inactivity",
    "enabled": true,
    "parameters": {
        "days": "2"
    }
}
```

**Use Case**: Detect communication failures, GPS device malfunctions, or equipment theft.

---

### Irrigation Management Warnings

#### `irrigation_start_end`
**Type**: `one-time`  
**Related To**: `irrigation`  
**Description**: Sends notifications when irrigation operations begin and end.

**Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| *None* | - | No | No parameters required for this warning type |

**Setting Message**: "Warn me at the start and end of irrigation."  
**Warning Message**: "Irrigation operation started on :start_date at :start_time in plot :plot and ended at :end_time."

**Example Configuration:**
```json
{
    "key": "irrigation_start_end",
    "enabled": true,
    "parameters": {}
}
```

**Use Case**: Track irrigation operations and ensure proper water management scheduling.

---

### Environmental Warnings

#### `frost_warning`
**Type**: `schedule-based`  
**Related To**: `farm`  
**Description**: Monitors weather conditions to predict potential frost events.

**Parameters:**
| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `days` | integer | Yes | Number of days in advance to warn about frost | `3` |

**Setting Message**: "Warn me :days days before a potential frost event."  
**Warning Message**: "There is a risk of frost in your farm in the next :days days. Take precautions."

**Example Configuration:**
```json
{
    "key": "frost_warning",
    "enabled": true,
    "parameters": {
        "days": "2"
    }
}
```

**Use Case**: Protect crops from frost damage by providing advance warning for protective measures.

---

#### `radiative_frost_warning`
**Type**: `condition-based`  
**Related To**: `farm`  
**Description**: Monitors specific weather conditions that indicate radiative frost risk.

**Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| *None* | - | No | No parameters required for this warning type |

**Setting Message**: "Warn me about radiative frost risk."  
**Warning Message**: "There is a risk of radiative frost on :date. Take precautions."

**Example Configuration:**
```json
{
    "key": "radiative_frost_warning",
    "enabled": true,
    "parameters": {}
}
```

**Use Case**: Immediate frost protection when specific atmospheric conditions are detected.

---

#### `oil_spray_warning`
**Type**: `schedule-based`  
**Related To**: `farm`  
**Description**: Monitors chilling requirement hours for proper oil spray timing.

**Parameters:**
| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `start_date` | string | Yes | Start date for chilling requirement calculation (Jalali format) | `"1403/01/01"` |
| `end_date` | string | Yes | End date for chilling requirement calculation (Jalali format) | `"1403/01/15"` |
| `hours` | integer | Yes | Minimum required chilling hours | `800` |

**Setting Message**: "Warn me if chilling requirement from :start_date to :end_date is less than :hours hours."  
**Warning Message**: "The chilling requirement in your farm from :start_date to :end_date was :hours hours. Please perform oil spraying."

**Example Configuration:**
```json
{
    "key": "oil_spray_warning",
    "enabled": true,
    "parameters": {
        "start_date": "1403/01/01",
        "end_date": "1403/01/15",
        "hours": "800"
    }
}
```

**Use Case**: Ensure proper dormancy breaking for fruit trees by monitoring chilling requirements.

---

### Pest Management Warnings

#### `pest_degree_day_warning`
**Type**: `schedule-based`  
**Related To**: `pests`  
**Description**: Monitors degree day accumulation for pest development prediction.

**Parameters:**
| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `pest` | string | Yes | Name of the pest to monitor | `"codling_moth"` |
| `start_date` | string | Yes | Start date for degree day calculation (Jalali format) | `"1403/03/01"` |
| `end_date` | string | Yes | End date for degree day calculation (Jalali format) | `"1403/03/30"` |
| `degree_days` | float | Yes | Minimum required degree days for pest development | `250.5` |

**Setting Message**: "Warn me if degree days for :pest pest from :start_date to :end_date is less than :degree_days."  
**Warning Message**: "The degree days for :pest pest from :start_date to :end_date was :degree_days."

**Example Configuration:**
```json
{
    "key": "pest_degree_day_warning",
    "enabled": true,
    "parameters": {
        "pest": "codling_moth",
        "start_date": "1403/03/01",
        "end_date": "1403/03/30",
        "degree_days": "250.5"
    }
}
```

**Use Case**: Predict pest emergence and plan integrated pest management strategies.

---

### Crop Management Warnings

#### `crop_type_degree_day_warning`
**Type**: `schedule-based`  
**Related To**: `crop_types`  
**Description**: Monitors degree day accumulation for crop development stages.

**Parameters:**
| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `crop_type` | string | Yes | Name of the crop type to monitor | `"apple"` |
| `start_date` | string | Yes | Start date for degree day calculation (Jalali format) | `"1403/02/01"` |
| `end_date` | string | Yes | End date for degree day calculation (Jalali format) | `"1403/04/30"` |
| `degree_days` | float | Yes | Minimum required degree days for crop development stage | `400.0` |

**Setting Message**: "Warn me if degree days for :crop_type crop_type from :start_date to :end_date is less than :degree_days."  
**Warning Message**: "The degree days for :crop_type crop_type from :start_date to :end_date was :degree_days."

**Example Configuration:**
```json
{
    "key": "crop_type_degree_day_warning",
    "enabled": true,
    "parameters": {
        "crop_type": "apple",
        "start_date": "1403/02/01",
        "end_date": "1403/04/30",
        "degree_days": "400.0"
    }
}
```

**Use Case**: Monitor crop development stages and plan agricultural activities like pruning, fertilizing, or harvesting.

## Notification Delivery System

The warning system supports multiple notification channels to ensure reliable delivery:

### Delivery Channels

1. **Database Notifications**
   - Stored in the `notifications` table
   - Accessible via the user's notification dashboard
   - Persistent storage for notification history

2. **Firebase Cloud Messaging (FCM)**
   - Real-time push notifications to mobile devices
   - Supports rich notifications with custom data payloads
   - Automatic retry mechanism for failed deliveries

3. **Email Notifications** (Optional)
   - Configurable email delivery for critical warnings
   - HTML-formatted messages with detailed information
   - Backup delivery method for important alerts

### Notification Structure

Each notification contains:

```json
{
    "id": "notification_uuid",
    "type": "App\\Notifications\\WarningNotification",
    "notifiable_type": "App\\Models\\User",
    "notifiable_id": 123,
    "data": {
        "message": "Formatted warning message",
        "warning_key": "tractor_stoppage",
        "parameters": {
            "tractor_name": "Tractor-001",
            "hours": "3",
            "date": "1403/03/15"
        },
        "priority": "high",
        "color": "warning"
    },
    "read_at": null,
    "created_at": "2024-03-15T10:30:00Z"
}
```

### Firebase Message Format

Firebase notifications include:

- **Title**: Warning category (e.g., "Tractor Stoppage Warning")
- **Body**: Human-readable warning message
- **Data Payload**: Structured data for app processing
- **Priority**: `high` for urgent warnings, `normal` for others
- **Color**: Visual indicator for notification grouping

## Data Models

### Warning Model

```php
class Warning extends Model
{
    protected $fillable = [
        'farm_id',      // Farm identifier
        'key',          // Warning type key
        'enabled',      // Active status
        'parameters',   // JSON configuration
        'type'          // Execution type
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'parameters' => 'array',
        'type' => 'string'
    ];
}
```

### Database Schema

```sql
CREATE TABLE warnings (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    farm_id BIGINT NOT NULL,
    key VARCHAR(255) NOT NULL,
    enabled BOOLEAN DEFAULT FALSE,
    parameters JSON,
    type VARCHAR(50) DEFAULT 'one-time',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unique_farm_warning (farm_id, key)
);
```

## Validation Rules

### Request Validation

The system validates incoming requests using Laravel's validation system with dynamic validation based on the warning key and its `setting-message-parameters` defined in `warnings.json`:

#### Base Validation Rules

```php
// Required fields
'key' => 'required|string',  // Must exist in warnings.json
'enabled' => 'required|boolean',
'parameters' => 'required|array',
'type' => 'sometimes|string|in:one-time,schedule-based,condition-based',
```

#### Dynamic Parameter Validation

The system automatically validates parameters based on the warning key's `setting-message-parameters` array. Each parameter is validated according to its type:

**Parameter Type Detection:**
- **Date Parameters** (`start_date`, `end_date`, `date`): Validated as Jalali date strings in format `YYYY/MM/DD` (e.g., `1403/01/15`)
  - Must be valid Jalali calendar dates (years 13xx or 14xx)
  - Automatically converted to Carbon instances after validation
  
- **Time Parameters** (`hours`, `days`): Validated as positive integers
  - `hours`: Must be integer ≥ 1
  - `days`: Must be integer ≥ 1
  
- **Numeric Parameters** (`degree_days`): Validated as numeric values
  - Must be numeric (integer or float)
  - Must be ≥ 0
  
- **String Parameters** (`pest`, `crop_type`): Validated as strings
  - Must be non-empty strings
  - Maximum length: 255 characters
  
- **Other Parameters**: Default to string validation

#### Parameter Restrictions

- **Only Allowed Parameters**: The system ensures that only parameters listed in the warning's `setting-message-parameters` array are provided. Any extra parameters will result in a validation error.
- **Required Parameters**: All parameters listed in `setting-message-parameters` are required when the warning is enabled.

### Parameter Validation Examples

#### Example 1: Tractor Stoppage Warning
```json
{
    "key": "tractor_stoppage",
    "enabled": true,
    "parameters": {
        "hours": 3  // ✅ Valid: integer ≥ 1
    }
}
```

**Validation Rules Applied:**
- `parameters.hours`: `required|integer|min:1`

#### Example 2: Oil Spray Warning
```json
{
    "key": "oil_spray_warning",
    "enabled": true,
    "parameters": {
        "start_date": "1403/01/01",  // ✅ Valid: Jalali date format
        "end_date": "1403/01/15",     // ✅ Valid: Jalali date format
        "hours": 800                  // ✅ Valid: integer ≥ 1
    }
}
```

**Validation Rules Applied:**
- `parameters.start_date`: `required|string|jalali_date_format`
- `parameters.end_date`: `required|string|jalali_date_format`
- `parameters.hours`: `required|integer|min:1`

#### Example 3: Pest Degree Day Warning
```json
{
    "key": "pest_degree_day_warning",
    "enabled": true,
    "parameters": {
        "pest": "codling_moth",       // ✅ Valid: string ≤ 255 chars
        "start_date": "1403/03/01",   // ✅ Valid: Jalali date format
        "end_date": "1403/03/30",     // ✅ Valid: Jalali date format
        "degree_days": 250.5          // ✅ Valid: numeric ≥ 0
    }
}
```

**Validation Rules Applied:**
- `parameters.pest`: `required|string|max:255`
- `parameters.start_date`: `required|string|jalali_date_format`
- `parameters.end_date`: `required|string|jalali_date_format`
- `parameters.degree_days`: `required|numeric|min:0`

### Common Validation Errors

#### Invalid Warning Key
```json
{
    "errors": {
        "key": ["The selected warning key is invalid."]
    }
}
```
**Cause**: The provided `key` does not exist in `warnings.json`.

#### Missing Required Parameter
```json
{
    "errors": {
        "parameters.hours": ["The parameter 'hours' is required for this warning type."]
    }
}
```
**Cause**: A required parameter from `setting-message-parameters` is missing.

#### Invalid Parameter Type
```json
{
    "errors": {
        "parameters.hours": ["The parameter 'hours' must be an integer."]
    }
}
```
**Cause**: Parameter value does not match the expected type (e.g., string instead of integer).

#### Invalid Jalali Date Format
```json
{
    "errors": {
        "parameters.start_date": ["The parameters.start_date must be a valid Jalali date in format YYYY/MM/DD (e.g., 1403/01/15)."]
    }
}
```
**Cause**: Date parameter is not in valid Jalali format or is outside valid date range.

#### Extra Parameters Not Allowed
```json
{
    "errors": {
        "parameters": ["The parameters field contains invalid parameters: extra_param"]
    }
}
```
**Cause**: Parameters provided that are not listed in the warning's `setting-message-parameters` array.

## Best Practices

### Configuration Recommendations

1. **Tractor Warnings**
   - Set reasonable thresholds (2-4 hours for stoppage, 1-2 days for inactivity)
   - Monitor during active farming seasons only
   - Configure different thresholds for different tractor types

2. **Environmental Warnings**
   - Enable frost warnings 2-3 days in advance
   - Monitor oil spray requirements during dormancy period
   - Adjust degree day thresholds based on local conditions

3. **Pest Management**
   - Set degree day thresholds based on local pest models
   - Monitor multiple pests simultaneously
   - Adjust thresholds based on pest life cycle stages

### Performance Considerations

1. **Batch Processing**: Warning checks are processed in batches to optimize performance
2. **Caching**: Warning definitions are cached to reduce database queries
3. **Queue Processing**: Notifications are queued to prevent blocking operations
4. **Rate Limiting**: Prevents notification spam during rapid condition changes

### Security Considerations

1. **Farm Isolation**: Warnings are scoped to specific farms
2. **User Authorization**: Only authorized users can configure warnings for their farms
3. **Input Sanitization**: All parameters are validated and sanitized
4. **Audit Trail**: Warning configurations are logged for compliance

## Error Handling

All error responses follow this format:
```json
{
    "message": "Error message description",
    "errors": {
        "field_name": [
            "Validation error message"
        ]
    }
}
```

Common HTTP Status Codes:
- `200 OK` - Request successful
- `400 Bad Request` - Missing required parameters
- `401 Unauthorized` - Authentication failed
- `422 Unprocessable Entity` - Validation errors

## Troubleshooting

### Common Issues

#### Warning Not Triggering

**Symptoms**: Warning is enabled but no notifications are received.

**Possible Causes**:
1. **Threshold Not Met**: Check if actual values exceed configured thresholds
2. **Service Not Running**: Ensure warning checking services are scheduled properly
3. **Invalid Parameters**: Verify all required parameters are provided and valid
4. **Date Range Issues**: Check if date ranges are correct and not in the past

**Solutions**:
```bash
# Check if warning services are running
php artisan queue:work

# Verify warning configuration
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "https://api.example.com/api/v1/warnings?related-to=tractors"
```

#### Parameter Validation Errors

**Symptoms**: 422 error when updating warning settings.

**Common Issues**:
1. **Missing Required Parameters**: Ensure all required parameters are provided
2. **Invalid Date Format**: Use proper Jalali date format (YYYY/MM/DD)
3. **Out of Range Values**: Check parameter values are within valid ranges

**Example Fix**:
```json
// Incorrect
{
    "key": "tractor_stoppage",
    "enabled": true,
    "parameters": {
        "hours": "abc"  // Invalid: must be integer
    }
}

// Correct
{
    "key": "tractor_stoppage",
    "enabled": true,
    "parameters": {
        "hours": "3"  // Valid: integer as string
    }
}
```

#### Notification Delivery Issues

**Symptoms**: Warning triggers but notifications not received.

**Possible Causes**:
1. **Firebase Configuration**: Check FCM server key and app configuration
2. **User Permissions**: Verify user has notification permissions
3. **Network Issues**: Check internet connectivity and firewall settings
4. **Queue Processing**: Ensure notification queue is being processed

**Debug Steps**:
```bash
# Check notification queue
php artisan queue:work --verbose

# Check database notifications
SELECT * FROM notifications WHERE notifiable_id = USER_ID ORDER BY created_at DESC;

# Test Firebase connection
php artisan tinker
>>> Notification::send($user, new TestNotification());
```

### Performance Optimization

#### Large Farm Operations

For farms with many tractors or complex operations:

1. **Batch Processing**: Process warnings in smaller batches
2. **Caching**: Enable Redis caching for warning definitions
3. **Queue Workers**: Run multiple queue workers for parallel processing
4. **Database Indexing**: Ensure proper indexes on warning-related tables

```bash
# Optimize queue processing
php artisan queue:work --sleep=3 --tries=3 --max-time=3600

# Enable caching
php artisan config:cache
php artisan route:cache
```

#### Monitoring and Logging

Enable comprehensive logging for warning system:

```php
// In config/logging.php
'channels' => [
    'warning' => [
        'driver' => 'single',
        'path' => storage_path('logs/warning.log'),
        'level' => 'info',
    ],
],
```

### API Rate Limiting

The warning system implements rate limiting to prevent abuse:

- **Standard Limit**: 100 requests per minute per user
- **Burst Limit**: 200 requests per minute for authenticated users
- **Warning Configuration**: 10 updates per minute per farm

### Migration and Updates

When updating warning definitions:

1. **Backup Configuration**: Always backup existing warning settings
2. **Gradual Rollout**: Test new warning types in staging environment
3. **User Communication**: Notify users of changes to warning behavior
4. **Fallback Support**: Maintain backward compatibility for existing configurations

### Support and Maintenance

#### Regular Maintenance Tasks

1. **Clean Old Notifications**: Archive notifications older than 90 days
2. **Update Warning Definitions**: Keep warning types current with agricultural best practices
3. **Monitor Performance**: Check warning processing times and queue depths
4. **Review Thresholds**: Adjust warning thresholds based on seasonal patterns

#### Monitoring Commands

```bash
# Check warning system health
php artisan warning:health-check

# Generate warning statistics
php artisan warning:stats --farm-id=123

# Test warning configurations
php artisan warning:test --key=tractor_stoppage --farm-id=123
```

### Integration Examples

#### Frontend Integration

```javascript
// Configure tractor stoppage warning
const configureWarning = async (warningConfig) => {
    const response = await fetch('/api/v1/warnings', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(warningConfig)
    });
    
    if (!response.ok) {
        const error = await response.json();
        console.error('Warning configuration failed:', error);
        return;
    }
    
    const result = await response.json();
    console.log('Warning configured successfully:', result);
};

// Example usage
configureWarning({
    key: 'tractor_stoppage',
    enabled: true,
    parameters: {
        hours: '3'
    }
});
```

#### Backend Integration

```php
// Check and process warnings
use App\Services\TractorStoppageWarningService;

$warningService = app(TractorStoppageWarningService::class);
$warningService->checkAndNotify();

// Custom warning implementation
class CustomWarningService extends WarningService
{
    public function checkCustomCondition(): bool
    {
        // Custom warning logic
        return $this->evaluateCondition();
    }
}
```

This comprehensive documentation provides developers and users with complete information about the warning system, including detailed parameter specifications, validation rules, troubleshooting guides, and integration examples.
