
#!/bin/bash

# One-line Docker deployment script for OpenSinop Production
# Usage: ./docker/run.sh

echo "🚀 Deploying OpenSinop Production with Docker..."

# Check if .env file exists in the current directory

# Read variables from .env file
export $(grep -v '^#' .env | xargs)

# Build the Docker image first
echo "📦 Building optimized Docker image..."
docker build \
    --build-arg APP_NAME=OpenSinop \
    --build-arg APP_ENV=production \
    --build-arg APP_DEBUG=false \
    --build-arg APP_URL=https://koami.koamishin.org \
    -t koamishin/opensinop:latest -f docker/Dockerfile .

# Ask to publish
if [[ "$*" == *"--publish"* ]]; then
    PUBLISH="y"
else
    read -p "Do you want to publish the image to Docker Hub? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        PUBLISH="y"
    fi
fi

if [ "$PUBLISH" = "y" ]; then
    echo "📦 Publishing image to Docker Hub..."
    docker push koamishin/opensinop:latest
fi

# Run the Docker container
echo "🚀 Starting Docker container..."
docker run -d \
    --name opensinop-production \
    --restart unless-stopped \
    --env-file .env \
    --network host \
    -v opensinop-storage:/app/storage \
    -v opensinop-logs:/app/storage/logs \
    --health-cmd "curl -f http://localhost:8000 || exit 1" \
    --health-interval 30s \
    --health-timeout 10s \
    --health-retries 3 \
    --health-start-period 60s \
    koamishin/opensinop:latest

echo "✅ OpenSinop deployed successfully!"
echo "🌐 Application is available at: http://localhost:8000"
echo "📊 Horizon dashboard: http://localhost:8000/horizon"
echo "💓 Pulse dashboard: http://localhost:8000/pulse"
echo ""
echo "Note: Using host networking mode. The container shares your host's network."
echo "To view logs: docker logs -f koamistarterkit-production"
echo "To stop: docker stop koamistarterkit-production"
echo "To restart: docker restart koamistarterkit-production"
