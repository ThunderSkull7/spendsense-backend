<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Your Password — SpendSense</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      background-color: #f8fafc;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      color: #0f172a;
      line-height: 1.6;
    }
    .wrapper {
      width: 100%;
      background-color: #f8fafc;
      padding: 32px 16px;
    }
    .container {
      max-width: 560px;
      margin: 0 auto;
      background-color: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      overflow: hidden;
    }
    .header {
      background-color: #042f2e;
      padding: 24px 32px;
      text-align: left;
    }
    .header h1 {
      margin: 0;
      color: #14b8a6;
      font-size: 22px;
      font-weight: 700;
      letter-spacing: -0.02em;
    }
    .body {
      padding: 32px;
    }
    .body p {
      margin: 0 0 16px;
      font-size: 15px;
      color: #334155;
    }
    .button-container {
      margin: 28px 0;
      text-align: left;
    }
    .button {
      display: inline-block;
      background-color: #0d9488;
      color: #ffffff !important;
      text-decoration: none;
      padding: 12px 24px;
      border-radius: 6px;
      font-size: 15px;
      font-weight: 600;
    }
    .token-box {
      background-color: #f1f5f9;
      border: 1px dashed #cbd5e1;
      border-radius: 6px;
      padding: 14px 18px;
      margin: 20px 0;
    }
    .token-box p {
      margin: 0 0 6px;
      font-size: 13px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
    }
    .token-box code {
      font-family: "Courier New", Courier, monospace;
      font-size: 15px;
      color: #0f172a;
      word-break: break-all;
    }
    .notice {
      font-size: 13px;
      color: #64748b;
      margin-top: 24px;
      border-top: 1px solid #f1f5f9;
      padding-top: 20px;
    }
    .footer {
      background-color: #f8fafc;
      padding: 20px 32px;
      text-align: center;
      border-top: 1px solid #e2e8f0;
      font-size: 12px;
      color: #94a3b8;
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="container">
      <div class="header">
        <h1>SpendSense</h1>
      </div>
      <div class="body">
        <p>Hello,</p>
        <p>We received a request to reset the password for your SpendSense account associated with <strong>{{ $email }}</strong>.</p>
        <p>Click the button below to choose a new password. This reset link is valid for <strong>{{ $expiryMinutes }} minutes</strong> and can only be used once.</p>
        
        <div class="button-container">
          <a href="{{ $resetUrl }}" class="button" target="_blank" rel="noopener noreferrer">Reset Password</a>
        </div>

        <p>If you prefer to enter your reset token manually in the SpendSense recovery form, use the token below:</p>

        <div class="token-box">
          <p>Your Reset Token</p>
          <code>{{ $token }}</code>
        </div>

        <div class="notice">
          <p>If you did not request a password reset, please ignore this email or review your account security. Your current password remains secure.</p>
          <p style="word-break: break-all; font-size: 12px; color: #94a3b8;">
            Button not working? Copy and paste this URL into your browser:<br>
            <a href="{{ $resetUrl }}" style="color: #0d9488;">{{ $resetUrl }}</a>
          </p>
        </div>
      </div>
      <div class="footer">
        &copy; {{ date('Y') }} SpendSense. Personal Finance Management. All rights reserved.
      </div>
    </div>
  </div>
</body>
</html>

