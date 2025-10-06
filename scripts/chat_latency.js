/**
 * k6 Load Test Script for ChatbotPlatform
 * 
 * Tests /v1/chat/completions endpoint to establish baseline latency metrics
 * 
 * Usage:
 *   k6 run --vus 20 --duration 2m scripts/chat_latency.js
 *   k6 run --vus 20 --duration 2m -e API_KEY=your-key -e BASE_URL=https://your-domain.com scripts/chat_latency.js
 * 
 * Environment Variables:
 *   - BASE_URL: Base URL of the API (default: http://localhost:8000)
 *   - API_KEY: Bearer token for authentication
 *   - TENANT_ID: Tenant ID to test (default: 1)
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');
const latency = new Trend('latency_ms');
const tokenUsage = new Trend('token_usage');
const costPerRequest = new Trend('cost_per_request_usd');
const successRate = new Rate('success_rate');

// Configuration
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const API_KEY = __ENV.API_KEY || 'test-api-key';
const TENANT_ID = __ENV.TENANT_ID || '1';

// Test queries (mix of simple and complex)
const testQueries = [
    "What are your opening hours?",
    "Do you offer wheelchair accessibility?",
    "Tell me about your services",
    "How can I contact customer support?",
    "What are the payment methods accepted?",
    "Can you explain your refund policy?",
    "Where is your main office located?",
    "What are the requirements for registration?",
    "Do you have any special offers?",
    "How do I reset my password?",
];

export const options = {
    // Thresholds
    thresholds: {
        'http_req_duration': ['p(95)<2500'], // P95 should be less than 2.5s
        'http_req_failed': ['rate<0.05'],    // Error rate should be less than 5%
        'latency_ms': ['p(95)<2500'],
        'success_rate': ['rate>0.95'],
    },
    
    // Load stages
    stages: [
        { duration: '30s', target: 10 },  // Ramp-up to 10 users
        { duration: '1m', target: 20 },   // Stay at 20 users
        { duration: '30s', target: 0 },   // Ramp-down to 0 users
    ],
};

export function setup() {
    console.log(`🚀 Starting load test against ${BASE_URL}`);
    console.log(`📊 Target: P95 latency < 2.5s`);
    
    // Health check
    const healthCheck = http.get(`${BASE_URL}/up`);
    if (healthCheck.status !== 200) {
        throw new Error(`Health check failed: ${healthCheck.status}`);
    }
    
    console.log(`✅ Health check passed`);
    return { baseUrl: BASE_URL, apiKey: API_KEY };
}

export default function (data) {
    // Select random query
    const query = testQueries[Math.floor(Math.random() * testQueries.length)];
    
    // Prepare request
    const url = `${data.baseUrl}/api/v1/chat/completions`;
    const payload = JSON.stringify({
        model: 'gpt-4o-mini',
        messages: [
            { role: 'user', content: query }
        ],
        temperature: 0.7,
        max_tokens: 500,
        stream: false,
    });
    
    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${data.apiKey}`,
            'X-Tenant-ID': TENANT_ID,
            'X-Request-ID': `k6-${__VU}-${__ITER}`,
        },
        timeout: '30s',
    };
    
    // Make request
    const startTime = Date.now();
    const response = http.post(url, payload, params);
    const endTime = Date.now();
    const requestLatency = endTime - startTime;
    
    // Record metrics
    latency.add(requestLatency);
    
    // Check response
    const success = check(response, {
        'status is 200': (r) => r.status === 200,
        'has choices': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.choices && body.choices.length > 0;
            } catch (e) {
                return false;
            }
        },
        'has usage data': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.usage && body.usage.total_tokens > 0;
            } catch (e) {
                return false;
            }
        },
        'latency < 2.5s': (r) => requestLatency < 2500,
    });
    
    successRate.add(success);
    errorRate.add(!success);
    
    // Extract token usage and cost if available
    if (response.status === 200) {
        try {
            const body = JSON.parse(response.body);
            if (body.usage) {
                tokenUsage.add(body.usage.total_tokens);
                
                // Calculate cost (gpt-4o-mini: $0.15/1M input, $0.60/1M output)
                const inputCost = (body.usage.prompt_tokens * 0.15) / 1000000;
                const outputCost = (body.usage.completion_tokens * 0.60) / 1000000;
                const totalCost = inputCost + outputCost;
                costPerRequest.add(totalCost);
            }
        } catch (e) {
            console.error('Failed to parse response:', e);
        }
    }
    
    // Think time (simulate real user behavior)
    sleep(Math.random() * 2 + 1); // 1-3 seconds
}

export function handleSummary(data) {
    const p95Latency = data.metrics.latency_ms.values['p(95)'];
    const avgLatency = data.metrics.latency_ms.values.avg;
    const errorRate = data.metrics.errors.values.rate * 100;
    const avgTokens = data.metrics.token_usage ? data.metrics.token_usage.values.avg : 0;
    const avgCost = data.metrics.cost_per_request_usd ? data.metrics.cost_per_request_usd.values.avg : 0;
    
    console.log('');
    console.log('📊 ===== BASELINE METRICS =====');
    console.log(`   P95 Latency: ${p95Latency.toFixed(2)} ms ${p95Latency < 2500 ? '✅' : '❌'}`);
    console.log(`   Avg Latency: ${avgLatency.toFixed(2)} ms`);
    console.log(`   Error Rate: ${errorRate.toFixed(2)}% ${errorRate < 5 ? '✅' : '❌'}`);
    console.log(`   Avg Tokens: ${avgTokens.toFixed(0)}`);
    console.log(`   Avg Cost: $${avgCost.toFixed(6)}`);
    console.log(`   Total Requests: ${data.metrics.http_reqs.values.count}`);
    console.log('================================');
    console.log('');
    
    return {
        'stdout': JSON.stringify(data, null, 2),
        'baseline-report.json': JSON.stringify({
            timestamp: new Date().toISOString(),
            p95_latency_ms: p95Latency,
            avg_latency_ms: avgLatency,
            error_rate_percent: errorRate,
            avg_tokens: avgTokens,
            avg_cost_usd: avgCost,
            total_requests: data.metrics.http_reqs.values.count,
            thresholds_passed: p95Latency < 2500 && errorRate < 5,
        }, null, 2),
    };
}

