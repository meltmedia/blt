#!/bin/sh

# Update $PATH to include blt command
PATH=/app/vendor/bin:$PATH

# ensure settings are set up properly
blt blt:init:settings

if [[ ( -f "/app/.meltmedia" ) && -s "/app/.meltmedia"  ]]; then
  exit 0;
fi

echo "Setting up your project for the first time"

# create symbolic link to ~/.acquia
if [ ! -f "~/.acquia" ]; then
  ln -s /user/.acquia ~/.acquia
fi

# execute BLT setup and recipes
blt setup -v
blt recipes:aliases:init:acquia
blt recipes:cloud-hooks:init

# enable config split
drush en config_split -y
drush cex -y
blt recipes:config:init:splits
drush cim -y

# finalize installation
echo $(date) > /app/.meltmedia

# initialize git and commit base code
cd /app
git init
git add .
