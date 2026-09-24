#!/bin/bash

##
## End-to-End Streaming Test
##
## Demonstrates that streaming works by showing chunks arriving over time.
## Run this after: ./sfphp serve (in background)
##

set -e

echo "========================================="
echo "  E2E Streaming Test with Timestamps"
echo "========================================="
echo ""

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

TEST_URL="${1:-http://localhost:8000/stream}"
TEST_TIMEOUT="${2:-5}"

echo "Test URL: $TEST_URL"
echo "Timeout: ${TEST_TIMEOUT}s"
echo ""
echo "Starting test at: $(date '+%H:%M:%S.%3N')"
echo "---"
echo ""

# Run curl with -N (unbuffered) and capture output with timestamps
start_time=$(date +%s%3N)

curl -N --max-time "$TEST_TIMEOUT" "$TEST_URL" 2>/dev/null | while IFS= read -r line; do
    current_time=$(date +%s%3N)
    elapsed=$((current_time - start_time))
    printf "${BLUE}[%04dms]${NC} %s\n" "$elapsed" "$line"
done

echo ""
echo "---"
echo "Test completed at: $(date '+%H:%M:%S.%3N')"
echo ""
echo "✓ If you see chunks arriving at 200-300ms intervals,"
echo "  streaming is working correctly!"
echo ""
echo "========================================="
echo ""

# Test SSE endpoint too
echo "Testing SSE endpoint..."
echo ""
echo "Start time: $(date '+%H:%M:%S.%3N')"
echo "---"
echo ""

start_time=$(date +%s%3N)

curl -N --max-time 3 "$TEST_URL/sse" 2>/dev/null | head -20 | while IFS= read -r line; do
    current_time=$(date +%s%3N)
    elapsed=$((current_time - start_time))
    printf "${GREEN}[%04dms]${NC} %s\n" "$elapsed" "$line"
done

echo ""
echo "---"
echo "SSE test completed at: $(date '+%H:%M:%S.%3N')"
echo ""
echo "✓ If you see 'event:' and 'data:' lines with timestamps,"
echo "  SSE streaming is working correctly!"
echo ""
