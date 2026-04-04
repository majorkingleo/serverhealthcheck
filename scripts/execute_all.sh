#!/usr/bin/env bash
./check_disk.py 80 90
sudo ./check_fs_mirror.py
sudo ./check_smart.py
