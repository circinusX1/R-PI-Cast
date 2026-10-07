#!/bin/bash

# Ensure the script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "Error: This script must be run as root (use sudo)." >&2
  exit 1
fi


# installing needs
echo "Installing needed packages: cmake ffmpeg mpv alsa mesa libdrm fbi fbset. Y/Ctrl+C"
read yn
sudo apt update
sudo apt install -y mpv build-essential cmake ffmpeg alsa-utils mesa-va-drivers libdrm2 fbi fbset 

rm -rf rpi-fastcast
cmake .
make

# Check if required files exist in the current directory
if [ ! -f "./rpi-fastcast.service" ]; then
  echo "Error: 'rpi-fastcast.service' not found in the current folder." >&2
  exit 1
fi

if [ ! -f "./rpi-fastcast" ]; then
  echo "Error: 'rpi-fastcast' not found in the current folder." >&2
  exit 1
fi

# Ask the user to provide the PORT for R-PI within range [3000..9000]
PORT=""
while true; do
  read -p "Enter port for R-PI [3000-9000]: " PORT
  if [[ "$PORT" =~ ^[0-9]+$ ]] && [ "$PORT" -ge 3000 ] && [ "$PORT" -le 9000 ]; then
    break
  else
    echo "Invalid input. Please enter a valid number between 3000 and 9000."
  fi
done

# Ask user to provide access password (hidden input)
read -s -p "Enter access password: " PASSWORD
echo
if [ -z "$PASSWORD" ]; then
  echo "Error: Password cannot be empty." >&2
  exit 1
fi



# Define paths
CURRENT_DIR="$(pwd)"
EXECUTABLE_PATH="$CURRENT_DIR/rpi-fastcast"
TARGET_SERVICE="/etc/systemd/system/rpi-fastcast.service"

echo "Configuring service..."

# Replace placeholders and write to /etc/systemd/system/
# Using '|' as the delimiter for sed to avoid path collision issues
sed -e "s|%PWD%|$EXECUTABLE_PATH|g" \
    -e "s|%PORT%|$PORT|g" \
    -e "s|%PASS%|$PASSWORD|g" \
    "./rpi-fastcast.service" > "$TARGET_SERVICE"

# Reload systemd, enable, and start the service
echo "Enabling and starting rpi-fastcast service..."
systemctl daemon-reload
systemctl enable rpi-fastcast.service
systemctl start rpi-fastcast.service

echo "Success! The rpi-fastcast service has been installed, enabled, and started."
systemctl status rpi-fastcast.service --no-pager
