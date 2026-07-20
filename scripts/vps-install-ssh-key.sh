#!/usr/bin/env bash
set -euo pipefail
mkdir -p /root/.ssh
chmod 700 /root/.ssh
KEY='ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPIVzCNZ0ZphcvVaytpqr8+DkKk4p/z00QHOfUVuAng7 future-account-deploy'
grep -qF "$KEY" /root/.ssh/authorized_keys 2>/dev/null || echo "$KEY" >> /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
echo "SSH key installed"
