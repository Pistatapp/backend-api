# Mobile Device API Documentation

This document describes the API endpoints for mobile device connection and status checking.

## Base URL

```
https://your-domain.com/api/mobile
```

## Authentication

These endpoints are **public** and do not require authentication. They are designed for device registration and status checking before user login.

---

## Endpoints

### 1. Connect Device

Register or update a mobile device by linking it to a GPS device record using SIM number.

**Endpoint:** `POST /api/mobile/connect`

**Description:**  
Connects a mobile device to the system by updating the GPS device record with the device fingerprint and IMEI. If the device is already connected (IMEI is set), it returns the existing connection status.

#### Request Body

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| `sim_number` | string | Yes | Iranian mobile format (with or without leading zero) | The SIM card number associated with the GPS device |
| `device_fingerprint` | string | Yes | Max 255 characters | Unique identifier for the mobile device (e.g., Android ID, iOS identifier) |
| `imei` | string | Yes | Exactly 16 digits, numeric only | International Mobile Equipment Identity (IMEI) number |

#### Request Example

```json
{
  "sim_number": "09123456789",
  "device_fingerprint": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
  "imei": "1234567890123456"
}
```

#### Response Scenarios

##### Success: Device Connected (New Connection)

**Status Code:** `200 OK`

```json
{
  "status": "connected",
  "message": "Device connected successfully",
  "device_id": 42
}
```

##### Success: Device Already Connected

**Status Code:** `200 OK`

```json
{
  "status": "connected",
  "message": "Device is already connected and approved",
  "device_id": 42
}
```

##### Error: Device Not Found

**Status Code:** `404 Not Found`

```json
{
  "status": "not-found",
  "message": "No device record found for this user"
}
```

##### Error: Validation Failed

**Status Code:** `422 Unprocessable Entity`

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "sim_number": [
      "The sim number field is required."
    ],
    "imei": [
      "The imei must be 16 digits.",
      "The imei format is invalid."
    ],
    "device_fingerprint": [
      "The device fingerprint field is required."
    ]
  }
}
```

#### cURL Example

```bash
curl -X POST https://your-domain.com/api/mobile/connect \
  -H "Content-Type: application/json" \
  -d '{
    "sim_number": "09123456789",
    "device_fingerprint": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
    "imei": "1234567890123456"
  }'
```

#### JavaScript/Fetch Example

```javascript
const connectDevice = async (simNumber, deviceFingerprint, imei) => {
  try {
    const response = await fetch('https://your-domain.com/api/mobile/connect', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        sim_number: simNumber,
        device_fingerprint: deviceFingerprint,
        imei: imei,
      }),
    });

    const data = await response.json();

    if (response.ok) {
      console.log('Device connected:', data);
      return data;
    } else {
      console.error('Connection failed:', data);
      throw new Error(data.message || 'Connection failed');
    }
  } catch (error) {
    console.error('Error:', error);
    throw error;
  }
};

// Usage
connectDevice('09123456789', 'device-fingerprint-123', '1234567890123456');
```

---

### 2. Check Connection Status

Check the connection status of a device by its fingerprint.

**Endpoint:** `POST /api/mobile/connection-status`

**Description:**  
Returns the connection status of a device based on its fingerprint. A device is considered "connected" if it exists in the system and has an IMEI assigned.

#### Request Body

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| `device_fingerprint` | string | Yes | Max 255 characters | Unique identifier for the mobile device |

#### Request Example

```json
{
  "device_fingerprint": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
}
```

#### Response Scenarios

##### Success: Device Connected

**Status Code:** `200 OK`

```json
{
  "status": "connected",
  "device_fingerprint": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
}
```

##### Success: Device Not Connected

**Status Code:** `200 OK`

```json
{
  "status": "not-connected",
  "device_fingerprint": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
}
```

**Note:** This status is returned when:
- The device fingerprint doesn't exist in the system, OR
- The device exists but doesn't have an IMEI assigned yet

##### Error: Validation Failed

**Status Code:** `422 Unprocessable Entity`

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "device_fingerprint": [
      "The device fingerprint field is required."
    ]
  }
}
```

#### cURL Example

```bash
curl -X POST https://your-domain.com/api/mobile/connection-status \
  -H "Content-Type: application/json" \
  -d '{
    "device_fingerprint": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
  }'
```

#### JavaScript/Fetch Example

```javascript
const checkConnectionStatus = async (deviceFingerprint) => {
  try {
    const response = await fetch('https://your-domain.com/api/mobile/connection-status', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        device_fingerprint: deviceFingerprint,
      }),
    });

    const data = await response.json();

    if (response.ok) {
      if (data.status === 'connected') {
        console.log('Device is connected');
      } else {
        console.log('Device is not connected');
      }
      return data;
    } else {
      console.error('Status check failed:', data);
      throw new Error(data.message || 'Status check failed');
    }
  } catch (error) {
    console.error('Error:', error);
    throw error;
  }
};

// Usage
checkConnectionStatus('device-fingerprint-123');
```

---

## Status Values

### Connect Endpoint Status Values

- `connected` - Device is successfully connected (either newly connected or already connected)
- `not-found` - No GPS device record found for the provided SIM number

### Connection Status Endpoint Status Values

- `connected` - Device exists and has IMEI assigned
- `not-connected` - Device doesn't exist or doesn't have IMEI assigned

---

## Error Handling

All endpoints follow standard HTTP status codes:

- `200 OK` - Request successful
- `404 Not Found` - Resource not found (connect endpoint only)
- `422 Unprocessable Entity` - Validation error
- `500 Internal Server Error` - Server error

Error responses include a `message` field and, for validation errors, an `errors` object with field-specific error messages.

---

## Implementation Notes

### Device Fingerprint

The `device_fingerprint` should be a unique, stable identifier for the device. Common implementations:

- **Android:** Use `Settings.Secure.ANDROID_ID` or a combination of device identifiers
- **iOS:** Use `UIDevice.identifierForVendor` or `ASIdentifierManager.advertisingIdentifier`

### IMEI Format

- Must be exactly 16 digits
- Numeric characters only (0-9)
- No spaces, dashes, or other characters

### SIM Number Format

- Accepts Iranian mobile number format
- Can include or exclude leading zero
- Examples: `09123456789`, `9123456789`

---

## Workflow Example

### Initial Device Setup

1. **Check Status** - Call `connection-status` to see if device is already connected
   ```javascript
   const status = await checkConnectionStatus(deviceFingerprint);
   if (status.status === 'connected') {
     // Device already connected, proceed to app
     return;
   }
   ```

2. **Connect Device** - If not connected, call `connect` with SIM number and IMEI
   ```javascript
   const result = await connectDevice(simNumber, deviceFingerprint, imei);
   if (result.status === 'connected') {
     // Device connected successfully, save device_id
     localStorage.setItem('device_id', result.device_id);
   }
   ```

### Periodic Status Check

Apps can periodically check connection status to ensure the device remains connected:

```javascript
// Check every 5 minutes
setInterval(async () => {
  const status = await checkConnectionStatus(deviceFingerprint);
  if (status.status !== 'connected') {
    // Reconnect device
    await connectDevice(simNumber, deviceFingerprint, imei);
  }
}, 5 * 60 * 1000);
```

---

## Version History

- **v1.0** - Initial API documentation
  - Connect endpoint using SIM number
  - Connection status endpoint

---

## Support

For API support or questions, please contact the development team or refer to the main API documentation.
