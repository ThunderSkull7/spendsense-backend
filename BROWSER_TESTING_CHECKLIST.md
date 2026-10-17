# SpendSense Browser Testing Checklist

## Manual Testing Guide

### Setup
1. Backend: `php artisan serve --host=127.0.0.1 --port=8002` (current port)
   - Note: `php artisan` currently aborts until `backend/.env` is re-saved as UTF-8 (it is UTF-16 encoded; contents unchanged). This file was intentionally not modified during remediation.
2. Frontend: serve the `frontend` folder over HTTP and open it via `http://` (e.g. VS Code Live Server on `http://127.0.0.1:5500/index.html`, or `php -S 127.0.0.1:5500` inside `frontend/`). A `file://` path cannot work with the cookie-based API (Sanctum/CSRF require a real HTTP origin).

---

## Test Cases

### 1. Registration succeeds and returns HTTP 201
**Steps:**
1. Click "Sign Up" or go to `/authentication/signup.html`
2. Fill form: Name, Email, Password, Confirm Password
3. Submit form

**Expected:**
- ✅ Response: HTTP 201 Created
- ✅ User is auto-logged in
- ✅ Session cookie set
- ✅ "Registration successful! Please log in." message

---

### 2. Login establishes an authenticated session
**Steps:**
1. Go to `/authentication/login.html`
2. Fill form with registration credentials
3. Submit form

**Expected:**
- ✅ Response: HTTP 200 OK
- ✅ Session cookie set (check Application > Cookies)
- ✅ User object returned with id, name, email
- ✅ "Welcome back!" message

---

### 3. Current-user data loads after login
**Steps:**
1. Navigate to `/dashboard/dashboard.html`
2. Check browser console (F12 > Console)

**Expected:**
- ✅ No 401 errors
- ✅ Dashboard stats load
- ✅ Analytics endpoints return 200

---

### 4. Expense CRUD operations work
**Steps:**

**Create:** `/expense-management/add-expense.html` → Add expense  
**Read:** `/expense-management/expenses.html` → View list  
**Update:** Click edit → Change amount  
**Delete:** Click delete → Confirm  

**Expected:**
- ✅ Create: HTTP 201, visible in list
- ✅ Read: All expenses with categories
- ✅ Update: HTTP 200, changes persist
- ✅ Delete: HTTP 204, removed

---

### 5. Income and Category forms work
**Steps:**

**Income:** `/income-management/income.html` → Add income  
**Category:** `/categories-management/categories.html` → Add category  

**Expected:**
- ✅ Income: HTTP 201, visible in list
- ✅ Category: HTTP 201, appears in dropdowns

---

### 6. Budget creation and dashboard analytics work
**Steps:**

**Budget:** `/budget-planning/budget.html` → Create budget  
**Analytics:** `/dashboard/dashboard.html` → Check stats  

**Expected:**
- ✅ Budget: HTTP 201, visible in list
- ✅ Analytics: Usage percentage displays correctly
- ✅ Alerts: ≥80% shows warning

---

### 7. Logout clears session
**Steps:**
1. Go to any authenticated page
2. Click "Logout" in sidebar
3. Try to access `/api/user`

**Expected:**
- ✅ Logout: HTTP 200
- ✅ Session cookie cleared
- ✅ API calls return 401

---

### 8. Browser Console and Network show no errors
**Steps:**
1. Open DevTools (F12) > Network tab
2. Visit all pages: index, login, signup, dashboard, expenses, income, categories, budget
3. Check Console tab

**Expected:**
- ✅ No 404/500 errors
- ✅ No CORS errors
- ✅ No JavaScript errors

---

## API Test Commands

```bash
# Register
curl -X POST http://127.0.0.1:8002/api/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test User\",\"email\":\"test@example.com\",\"password\":\"password123\",\"password_confirmation\":\"password123\"}"

# Login
curl -X POST http://127.0.0.1:8002/api/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"test@example.com\",\"password\":\"password123\"}" ^
  -c cookies.txt

# Create Expense
curl -X POST http://127.0.0.1:8002/api/expenses ^
  -H "Content-Type: application/json" ^
  -b cookies.txt ^
  -d "{\"amount\":99.99,\"category_id\":1,\"expense_date\":\"2025-10-12\",\"description\":\"Test expense\"}"

# Logout
curl -X POST http://127.0.0.1:8002/api/logout -b cookies.txt
```

---

## Known Issues & Fixes Applied

| Issue | Status | Notes |
|-------|--------|-------|
| API Base URL Port | ✅ Fixed | Changed to port 8002 |
| Sanctum CSRF Cookie | ✅ | Route exists at `/sanctum/csrf-cookie` |
| Session Authentication | ✅ | Stateful mode enabled |
| CORS | ⚠️ May need | Add if frontend on different origin/port |

---

## Final Sign-off

- [ ] All 8 test cases pass
- [ ] No JavaScript errors in console
- [ ] No network errors (4xx/5xx)
- [ ] Session management works (login/logout)
- [ ] All CRUD operations functional
- [ ] Analytics dashboard updates correctly

**Tested By:** ___________________
**Date:** ___________________