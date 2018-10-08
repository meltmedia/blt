#!/bin/sh

# create symbolic link to ~/.acquia
if [ ! -f "~/.acquia" ]; then
  ln -s /user/.acquia ~/.acquia
fi

# Update $PATH to include blt command
PATH=/app/vendor/bin:$PATH
blt blt:init:settings
blt setup
blt recipes:aliases:init:acquia
