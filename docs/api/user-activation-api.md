# User Activation/Deactivation API Documentation

## Overview

The User Activation/Deactivation API provides endpoints for managing user account status. Administrators can activate or deactivate user accounts from their farms, controlling who can access the system.

**Key Features:**
- Activate user accounts to allow login
- Deactivate user accounts to prevent access
- Automatic logout for deactivated users
- Farm-based authorization (admins can only manage users from their farms)
- Real-time status checking via middleware

**Base URL:** `/api/users`

**Authentication:** All endpoints require `auth:sanctum` and `ensure.username`. Send the Bearer token in the `Authorization` header.

---

## Table of Contents

1. [Activate User](#1-activate-user)
2. [Deactivate User](#2-deactivate-user)
3. [User Account Status](#3-user-account-status)
4. [Authorization Rules](#4-authorization-rules)
5. [Login Behavior](#5-login-behavior)
6. [Error Handling](#6-error-handling)
7. [Example Usage](#7-example-usage)

---

## 1. Activate User

Activates a user account, allowing them to log in and access the system. Only admins can activate users from their own farms.

### Endpoint

```http
POST /api/users/{user}/activate
```

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| user | integer | Yes | User ID to activate. |

### Request Headers

```http
Authorization: Bearer {token}
Content-Type: application/json
```

### Authorization

- **Root users**: Can activate any user
- **Admin users**: Can only activate users who share at least one farm with them
- **Cannot activate**: Super-admin or root users

### Request Body

No request body required.

### Response

**Success (200 OK)**

```json
{
  "message": "User account activated successfully.",
  "user": {
    "id": 10,
    "name": "Jane Smith",
    "mobile": "09187654321",
    "username": "labour_09187654321",
    "is_active": true,
    "last_activity_at": "1403/11/25 09:00:00",
    "role": "labour",
    "can": {
      "update": true,
      "delete": true
    }
  }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| message | string | Success message |
| user | object | Updated user resource with `is_active: true` |

### Notes

- Sets `is_active` to `true` for the user
- User can immediately log in after activation
- If the user was previously deactivated while logged in, they will need to log in again
- The user resource includes all standard fields plus the `is_active` status

---

## 2. Deactivate User

Deactivates a user account, preventing them from logging in. The user is immediately logged out if currently authenticated. Only admins can deactivate users from their own farms.

### Endpoint

```http
POST /api/users/{user}/deactivate
```

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| user | integer | Yes | User ID to deactivate. |

### Request Headers

```http
Authorization: Bearer {token}
Content-Type: application/json
```

### Authorization

- **Root users**: Can deactivate any user
- **Admin users**: Can only deactivate users who share at least one farm with them
- **Cannot deactivate**: Super-admin or root users

### Request Body

No request body required.

### Response

**Success (200 OK)**

```json
{
  "message": "User account deactivated successfully.",
  "user": {
    "id": 10,
    "name": "Jane Smith",
    "mobile": "09187654321",
    "username": "labour_09187654321",
    "is_active": false,
    "last_activity_at": "1403/11/25 09:00:00",
    "role": "labour",
    "can": {
      "update": true,
      "delete": true
    }
  }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| message | string | Success message |
| user | object | Updated user resource with `is_active: false` |

### Notes

- Sets `is_active` to `false` for the user
- **All user tokens are immediately deleted** (user is logged out from all devices)
- User cannot log in while deactivated
- If user attempts to log in while deactivated, they will receive an error message
- The deactivation is immediate and takes effect on the next API request

---

## 3. User Account Status

### Account Activation Field

Users have an `is_active` boolean field that controls their ability to log in and access the system:

| Status | Value | Description |
|-------|-------|-------------|
| Active | `true` | User can log in and use the system normally |
| Deactivated | `false` | User cannot log in and will be logged out if currently authenticated |

### User Resource

The `is_active` field is included in all user resource responses:

```json
{
  "id": 10,
  "name": "Jane Smith",
  "mobile": "09187654321",
  "username": "labour_09187654321",
  "is_active": true,
  "last_activity_at": "1403/11/25 09:00:00",
  "role": "labour"
}
```

### Checking User Status

You can check a user's activation status by:

1. **List Users Endpoint**: `GET /api/users` - includes `is_active` for each user
2. **Get User Endpoint**: `GET /api/users/{user}` - includes `is_active` field
3. **Activate/Deactivate Response**: Returns updated user with `is_active` status

---

## 4. Authorization Rules

### Permission Matrix

| User Role | Can Activate/Deactivate |
|-----------|------------------------|
| **Root** | Any user (full access) |
| **Admin** | Users from their own farms only |
| **Admin** | Cannot activate/deactivate super-admin or root users |
| **Other Roles** | Cannot activate/deactivate users |

### Farm-based Authorization

- Admins can only manage users who **share at least one farm** with them
- This ensures farm-level access control
- Root users bypass all restrictions
- The system checks if the admin and target user have any common farms

### Authorization Flow

1. Check if user has admin or root role
2. If admin, verify they share at least one farm with target user
3. Verify target user is not super-admin or root (unless requester is root)
4. Allow or deny the operation

---

## 5. Login Behavior

### Active Account Login

When a user with `is_active: true` attempts to log in:

1. Normal authentication process proceeds
2. User receives authentication token
3. User can access the system

### Inactive Account Login

When a user with `is_active: false` attempts to log in:

1. Login attempt fails
2. User receives error message: *"Your account has been deactivated. Please contact your administrator."*
3. No authentication token is issued
4. User cannot access the system

### Automatic Logout

The system includes middleware (`EnsureUserIsActive`) that checks user status on every authenticated request:

- **If user becomes deactivated while logged in:**
  - User is automatically logged out on their next API request
  - All user tokens are deleted
  - User receives a 403 Forbidden response with error message

- **Middleware behavior:**
  - Runs on all authenticated API requests
  - Checks `is_active` status before processing request
  - Logs out user and invalidates session if inactive
  - Returns appropriate error response

### Token Management

- **On Activation**: User can create new tokens (normal login)
- **On Deactivation**: All existing tokens are immediately deleted
- **On Login Attempt**: Inactive users cannot create tokens

---

## 6. Error Handling

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success (activation/deactivation completed) |
| 401 | Unauthenticated (missing or invalid token) |
| 403 | Forbidden (no permission to activate/deactivate this user) |
| 404 | User not found |
| 422 | Validation error |

### Error Responses

#### 403 Forbidden - No Permission

```json
{
  "message": "This action is unauthorized."
}
```

**Common causes:**
- Admin trying to activate/deactivate user from different farm
- Admin trying to activate/deactivate super-admin or root user
- Non-admin user attempting to activate/deactivate

#### 404 Not Found - User Not Found

```json
{
  "message": "No query results for model [App\\Models\\User] {user_id}"
}
```

#### 422 Validation Error

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "user": ["The selected user is invalid."]
  }
}
```

### Login Error - Account Deactivated

When attempting to log in with a deactivated account:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "token": [
      "Your account has been deactivated. Please contact your administrator."
    ],
    "retries_left": 4
  }
}
```

### API Request Error - Account Deactivated

When making an API request while account is deactivated:

```json
{
  "message": "Your account has been deactivated. Please contact your administrator."
}
```

**Status Code:** 403 Forbidden

---

## 7. Example Usage

### Activate User

```javascript
// Activate a user account
const activateUser = async (userId, token) => {
  try {
    const response = await axios.post(
      `/api/users/${userId}/activate`,
      {},
      {
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      }
    );

    console.log(response.data.message); // "User account activated successfully."
    console.log(response.data.user.is_active); // true
    
    return response.data.user;
  } catch (error) {
    if (error.response?.status === 403) {
      console.error('Permission denied: Cannot activate this user');
    } else if (error.response?.status === 404) {
      console.error('User not found');
    }
    throw error;
  }
};

// Usage
await activateUser(10, authToken);
```

### Deactivate User

```javascript
// Deactivate a user account
const deactivateUser = async (userId, token) => {
  try {
    const response = await axios.post(
      `/api/users/${userId}/deactivate`,
      {},
      {
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      }
    );

    console.log(response.data.message); // "User account deactivated successfully."
    console.log(response.data.user.is_active); // false
    
    return response.data.user;
  } catch (error) {
    if (error.response?.status === 403) {
      console.error('Permission denied: Cannot deactivate this user');
    } else if (error.response?.status === 404) {
      console.error('User not found');
    }
    throw error;
  }
};

// Usage
await deactivateUser(10, authToken);
```

### Check User Status in List

```javascript
// Get users list and check activation status
const getUsersWithStatus = async (token) => {
  const response = await axios.get('/api/users', {
    headers: { Authorization: `Bearer ${token}` }
  });

  const users = response.data.data;
  
  users.forEach(user => {
    if (!user.is_active) {
      console.log(`${user.name} (${user.mobile}) is inactive`);
      // Show inactive badge or disable actions in UI
    }
  });

  return users;
};
```

### Handle Login Error for Deactivated Account

```javascript
// Handle login attempt for deactivated account
const handleLogin = async (mobile, token) => {
  try {
    const response = await axios.post('/api/auth/verify', {
      mobile: mobile,
      token: token
    });
    
    return response.data;
  } catch (error) {
    if (error.response?.status === 422) {
      const errors = error.response.data.errors;
      
      if (errors.token && errors.token.some(msg => 
        msg.includes('deactivated')
      )) {
        // Show user-friendly message
        alert('Your account has been deactivated. Please contact your administrator.');
        // Redirect to contact page or show support information
      }
    }
    throw error;
  }
};
```

### Toggle User Activation Status

```javascript
// Toggle user activation status
const toggleUserActivation = async (user, token) => {
  const endpoint = user.is_active 
    ? `/api/users/${user.id}/deactivate`
    : `/api/users/${user.id}/activate`;

  try {
    const response = await axios.post(endpoint, {}, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    });

    return response.data.user;
  } catch (error) {
    console.error('Failed to toggle user activation:', error);
    throw error;
  }
};

// Usage in React component
const UserRow = ({ user, onUpdate }) => {
  const handleToggle = async () => {
    try {
      const updatedUser = await toggleUserActivation(user, authToken);
      onUpdate(updatedUser);
    } catch (error) {
      // Show error toast/notification
    }
  };

  return (
    <tr>
      <td>{user.name}</td>
      <td>{user.mobile}</td>
      <td>
        {user.is_active ? (
          <span className="badge badge-success">Active</span>
        ) : (
          <span className="badge badge-danger">Inactive</span>
        )}
      </td>
      <td>
        <button onClick={handleToggle}>
          {user.is_active ? 'Deactivate' : 'Activate'}
        </button>
      </td>
    </tr>
  );
};
```

### Handle Automatic Logout on Deactivation

```javascript
// Set up axios interceptor to handle automatic logout
axios.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 403) {
      const message = error.response.data.message;
      
      if (message && message.includes('deactivated')) {
        // Clear local storage
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        
        // Redirect to login
        window.location.href = '/login';
        
        // Show notification
        alert('Your account has been deactivated. Please contact your administrator.');
      }
    }
    
    return Promise.reject(error);
  }
);
```

### Check if Current User is Active

```javascript
// Check current user status before making requests
const checkCurrentUserStatus = async (token) => {
  try {
    const response = await axios.get('/api/users/me', {
      headers: { Authorization: `Bearer ${token}` }
    });
    
    if (!response.data.is_active) {
      // User is inactive, handle accordingly
      localStorage.removeItem('token');
      window.location.href = '/login?error=account_deactivated';
    }
    
    return response.data;
  } catch (error) {
    if (error.response?.status === 403) {
      // Account might be deactivated
      localStorage.removeItem('token');
      window.location.href = '/login?error=account_deactivated';
    }
    throw error;
  }
};
```

---

## Summary

### Quick Reference

| Action | Endpoint | Method | Auth Required |
|--------|----------|--------|---------------|
| Activate User | `/api/users/{user}/activate` | POST | Yes (Admin/Root) |
| Deactivate User | `/api/users/{user}/deactivate` | POST | Yes (Admin/Root) |
| Check Status | `/api/users` or `/api/users/{user}` | GET | Yes |

### Key Points

1. **Authorization**: Only admins from shared farms can activate/deactivate users
2. **Immediate Effect**: Deactivation logs out user immediately
3. **Login Prevention**: Inactive users cannot log in
4. **Status Field**: `is_active` field in all user resources
5. **Automatic Logout**: Middleware checks status on every request

---

**Version:** 1.0  
**Last updated:** February 2026
