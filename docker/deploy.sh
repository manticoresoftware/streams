#!/bin/sh

# Fail on any error
set -e

# Ensure BUILD_TAG is set (provided by CI)
if [ -z "$BUILD_TAG" ]; then
  echo "Error: BUILD_TAG is not set. This script should be run by the CI pipeline."
  exit 1
fi

# Ensure REGISTRY is set (provided by CI)
if [ -z "$REGISTRY" ]; then
  echo "Error: REGISTRY is not set. This script should be run by the CI pipeline."
  exit 1
fi

# Ensure COMPONENT is set (passed as an argument)
if [ -z "$1" ]; then
  echo "Error: Component name not provided. Usage: $0 <component>"
  exit 1
fi

COMPONENT="$1"

echo "Building $COMPONENT with tag: $BUILD_TAG"

# Build and push only the specific tag
docker build --no-cache -f Dockerfile -t "${REGISTRY}/${COMPONENT}:${BUILD_TAG}" .
docker push "${REGISTRY}/${COMPONENT}:${BUILD_TAG}"

echo "Successfully built $COMPONENT with tag $BUILD_TAG"