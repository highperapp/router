#!/bin/bash

# HighPer Router Rust Library Build Script

set -e

echo "Building HighPer Router Rust library..."

# Check if Rust is installed
if ! command -v cargo &> /dev/null; then
    echo "Error: Rust/Cargo not found. Please install Rust first."
    echo "Visit: https://rustup.rs/"
    exit 1
fi

# Create target directory if it doesn't exist
mkdir -p target/release

# Build the library
echo "Compiling Rust library..."
cargo build --release

# Check if build was successful
if [ -f "target/release/libhighper_router.so" ] || [ -f "target/release/libhighper_router.dylib" ] || [ -f "target/release/highper_router.dll" ]; then
    echo "✅ Build successful!"
    
    # Copy to system locations (optional)
    if [ "$1" = "--install" ]; then
        echo "Installing library to system paths..."
        
        if [[ "$OSTYPE" == "linux-gnu"* ]]; then
            sudo cp target/release/libhighper_router.so /usr/local/lib/
            sudo ldconfig
            echo "✅ Installed to /usr/local/lib/libhighper_router.so"
        elif [[ "$OSTYPE" == "darwin"* ]]; then
            sudo cp target/release/libhighper_router.dylib /usr/local/lib/
            echo "✅ Installed to /usr/local/lib/libhighper_router.dylib"
        elif [[ "$OSTYPE" == "msys" ]] || [[ "$OSTYPE" == "win32" ]]; then
            cp target/release/highper_router.dll /c/Windows/System32/
            echo "✅ Installed to System32/highper_router.dll"
        fi
    fi
    
    echo "\n📍 Library locations:"
    find target/release -name "*highper_router*" -type f
    
else
    echo "❌ Build failed!"
    exit 1
fi

echo "\n🚀 HighPer Router Rust library ready!"
echo "To install system-wide, run: ./build.sh --install"