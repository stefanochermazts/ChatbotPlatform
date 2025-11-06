# 🌐 Browser Testing Guide - RAG Tester & Widget

**Date**: 2025-10-17  
**Backend Status**: ✅ All 3 paths verified working  
**Query to Test**: "telefono comando polizia locale"  
**Expected Answer**: "06.95898223"

---

## 🎯 Testing Goals

1. Verify RAG Tester UI shows correct phone number
2. Verify Widget UI shows correct phone number
3. Identify any frontend caching or rendering issues

---

## 📍 TEST 1: RAG Tester UI

### Step 1: Open RAG Tester
```
URL: https://chatbotplatform.test/admin/rag-test
(or http://chatbotplatform.test:8443/admin/rag-test if using HTTPS)
```

### Step 2: Clear Browser Cache
**Option A - Hard Refresh**:
- Windows: `Ctrl + F5` or `Ctrl + Shift + R`
- Mac: `Cmd + Shift + R`

**Option B - Clear All** (Recommended):
1. Press `F12` to open DevTools
2. Go to **Application** tab
3. Click **Clear storage** (left sidebar)
4. Check all boxes
5. Click **Clear site data**
6. Refresh page

### Step 3: Prepare DevTools
1. Keep DevTools open (`F12`)
2. Go to **Network** tab
3. Check **Preserve log** (to keep requests across page loads)
4. Check **Disable cache** (important!)

### Step 4: Submit Test Query
1. Select **Tenant**: "San Cesareo" (ID: 5)
2. Check **"Genera risposta con LLM"**
3. Query: `telefono comando polizia locale`
4. Click **"Esegui Test"**

### Step 5: Inspect Results

#### ✅ SUCCESS Indicators:
```
LLM Response contains:
- "06.95898223" ✅
- NOT "06.9587004" ❌

Citations section shows:
- 3 citations
- Doc 4350 in position #1 or #2
- Confidence: ~0.29
```

#### ❌ FAILURE Indicators:
```
- "06.9587004" (Carabinieri) appears
- "Non ho trovato informazioni" message
- 0 citations
- Empty response
```

### Step 6: Check Network Tab
1. Find request to `/admin/rag-test` (POST)
2. Click on it
3. Go to **Response** tab
4. Look for JSON response with `answer` field
5. **Copy the full response** and save it

### Step 7: Check Console Tab
1. Go to **Console** tab in DevTools
2. Look for any JavaScript errors (red text)
3. **Screenshot any errors**

---

## 📍 TEST 2: Widget UI

### Step 1: Find Widget Embed Page
```
Option A: Widget preview page (if exists)
URL: https://chatbotplatform.test/admin/tenants/5/widget-preview

Option B: Test page
URL: https://chatbotplatform.test/test-widget.html
```

If no test page exists, create one:
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Widget Test</title>
</head>
<body>
    <h1>Widget Test Page</h1>
    
    <script src="https://chatbotplatform.test/widget/js/chatbot-widget.js"></script>
    <script>
        window.ChatbotConfig = {
            tenantId: 5,
            apiUrl: 'https://chatbotplatform.test'
        };
    </script>
</body>
</html>
```

### Step 2: Clear Browser Cache
Same as RAG Tester (Step 2 above)

### Step 3: Prepare DevTools
1. Open DevTools (`F12`)
2. **Network** tab
3. **Preserve log** ✅
4. **Disable cache** ✅

### Step 4: Open Widget
1. Click the widget button (usually bottom-right)
2. Wait for widget to load

### Step 5: Submit Test Query
1. Type: `telefono comando polizia locale`
2. Press **Enter** or click **Send**
3. Wait for response (may take 5-15 seconds)

### Step 6: Inspect Results

#### ✅ SUCCESS Indicators:
```
Widget shows:
- "06.95898223" in response ✅
- Citations with links
- NOT "Non ho trovato informazioni"
```

#### ❌ FAILURE Indicators:
```
- "Non ho trovato informazioni sufficienti"
- "Non lo so con certezza"
- Empty response
- Loading forever
```

### Step 7: Check Network Tab
1. Find request to `/api/chat/completions` (POST)
2. Click on it
3. Check **Headers** tab:
   - Look for `X-Tenant-ID` header
   - Should be `5`
4. Check **Payload** tab:
   - Verify `messages` contains your query
5. Check **Response** tab:
   - Look for `choices[0].message.content`
   - Look for `citations` array
6. **Copy the full response** and save it

### Step 8: Check Console Tab
1. Look for widget debug logs (they start with emojis like 🔍 📋 🤖)
2. Look for any errors (red text)
3. **Screenshot the console output**

---

## 📊 Results Reporting Template

After testing, fill this out:

### RAG Tester Results
```
✅/❌ Test Status: _______
Phone Number Found: _______
Wrong Phone (Carabinieri): _______
Number of Citations: _______
Confidence: _______
Response Time: _______ ms
Any Errors: _______
```

### Widget Results
```
✅/❌ Test Status: _______
Phone Number Found: _______
Wrong Phone: _______
Citations Displayed: _______
Response Time: _______ ms
Any Errors: _______
```

### Network Response Samples
```json
// RAG Tester API Response:
{
  "answer": "...",
  "citations": [...],
  "confidence": ...
}

// Widget API Response:
{
  "choices": [{
    "message": {
      "content": "..."
    }
  }],
  "citations": [...]
}
```

---

## 🔍 Troubleshooting

### If RAG Tester Shows Wrong Phone:
1. Check if tenant dropdown is set to **"San Cesareo (ID: 5)"**
2. Try clearing cache again and retry
3. Check browser console for JavaScript errors
4. Verify Network response matches backend test results

### If Widget Shows "Non ho trovato informazioni":
1. Check Network → `/api/chat/completions` → Headers → verify `X-Tenant-ID: 5`
2. Check if widget has `tenantId` in config
3. Try clearing localStorage: `localStorage.clear()` in console
4. Check if API response has `citations` array (should have 3 items)

### If Both Fail:
1. Check if backend is running: `php artisan serve`
2. Check if Milvus is running
3. Check if database is accessible
4. Review `storage/logs/laravel.log` for backend errors

---

## 📸 What to Screenshot/Copy

Please provide:

1. **RAG Tester**:
   - Screenshot of the LLM response
   - Network tab: `/admin/rag-test` response (full JSON)
   - Console tab (if errors)

2. **Widget**:
   - Screenshot of widget conversation
   - Network tab: `/api/chat/completions` response (full JSON)
   - Console tab (especially widget debug logs)

3. **Both**:
   - Any error messages or warnings
   - Response times
   - Citation counts

---

## ✅ Success Criteria

**BOTH tests should show**:
- ✅ "06.95898223" in the response
- ✅ 3 citations
- ✅ Doc 4350 in top 3 citations
- ❌ NO "06.9587004" (Carabinieri)
- ❌ NO "Non ho trovato informazioni"

If both pass → **Task complete!** 🎉  
If one fails → Need to investigate that specific path  
If both fail → Need to check if backend changes are live

---

## 🚀 Ready to Test?

Follow the steps above and report back with the results. Good luck! 🎯

