#!/bin/bash

# Function to install WordPress plugin with retries
install_plugin() {
  local plugin=$1
  local version=$2
  local retries=3
  local sleep_seconds=5
  local install_command

  # Determine if a version is provided, and construct the install command accordingly
  if [[ -z "$version" ]]; then
    install_command="wp plugin install $plugin --activate"
  else
    install_command="wp plugin install $plugin --activate --version=$version"
  fi

  for ((i=1; i<=retries; i++)); do
    echo "Attempt $i: Installing $plugin..."
    if docker exec --user=www-data ci_runner_php_fpm bash -c "$install_command"; then
      echo "$plugin installed successfully."
      return 0
    else
      echo "Attempt $i failed! Retrying in $sleep_seconds seconds..."
      sleep $sleep_seconds
    fi
  done

  echo "Failed to install $plugin after $retries attempts."
  return 1
}

# Check for the presence of at least one argument
if [ $# -eq 0 ]; then
  echo "Usage: $0 plugin_slug_or_url [version]"
  exit 1
fi

# Call the install_plugin function with provided arguments
install_plugin "$1" "$2"
