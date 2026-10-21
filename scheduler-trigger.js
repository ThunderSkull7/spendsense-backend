#!/usr/bin/env node
/**
 * trigger-scheduler.js
 *
 * HTTP endpoint to trigger Laravel scheduler commands via HTTP.
 * This can be called by external schedulers (cron-job.org, GitHub Actions, etc.)
 * on free-tier hosting where Laravel's built-in scheduler doesn't run.
 *
 * Security:
 * - Requires SCHEDULER_SECRET environment variable (no default)
 * - Validates Authorization header with Bearer token
 * - Allowlisted commands only
 * - No command output returned to callers
 * - Bounded execution time and buffer
 */

const express = require('express');
const { execSync } = require('child_process');

const app = express();
app.use(express.json());

// Require SCHEDULER_SECRET - no default, fail fast if missing
const SCHEDULER_SECRET = process.env.SCHEDULER_SECRET;
if (!SCHEDULER_SECRET) {
  console.error('FATAL: SCHEDULER_SECRET environment variable is required but not set');
  process.exit(1);
}

const PORT = process.env.PORT || 3001;

const ALLOWED_COMMANDS = [
  'model:prune',
  'recurring-expenses:sync-reminders'
];

function verifyAuth(req, res, next) {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ error: 'Missing or invalid Authorization header' });
  }
  const token = authHeader.substring(7);
  if (token !== SCHEDULER_SECRET) {
    return res.status(403).json({ error: 'Invalid scheduler token' });
  }
  next();
}

app.post('/scheduler/run', verifyAuth, async (req, res) => {
  const { command } = req.body;
  
  if (!command || !ALLOWED_COMMANDS.includes(command)) {
    return res.status(400).json({ 
      error: `Invalid command. Allowed: ${ALLOWED_COMMANDS.join(', ')}` 
    });
  }

  try {
    const cmd = `php artisan ${command}`;
    console.log(`Executing: ${command}`);
    execSync(cmd, { 
      cwd: process.cwd(),
      encoding: 'utf8',
      timeout: 120000,
      maxBuffer: 1024 * 1024
    });
    // Do not return command output to caller
    res.json({ success: true });
  } catch (error) {
    console.error(`Command failed: ${command} - ${error.message}`);
    // Do not leak internal error details
    res.status(500).json({ error: 'Command execution failed' });
  }
});

app.get('/scheduler/health', (req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

const server = app.listen(PORT, () => {
  console.log(`Scheduler trigger server running on port ${PORT}`);
  console.log(`Allowed commands: ${ALLOWED_COMMANDS.join(', ')}`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received, shutting down gracefully');
  server.close(() => {
    console.log('Server closed');
    process.exit(0);
  });
});

process.on('SIGINT', () => {
  console.log('SIGINT received, shutting down gracefully');
  server.close(() => {
    console.log('Server closed');
    process.exit(0);
  });
});

module.exports = app;