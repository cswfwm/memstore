#!/usr/bin/env python3
"""把 htdocs 目录同步到主机远端目录（FTP，默认 /htdocs）。密码从环境变量读取，不落盘。"""
import os
import sys
import ftplib

HOST = os.environ.get('FTP_HOST', 'ftpupload.net')
USER = os.environ['FTP_USER']
PASS = os.environ['FTP_PASS']
LOCAL = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'htdocs')
REMOTE = os.environ.get('FTP_REMOTE', '/htdocs')

SKIP_DIRS = {'data'}
SKIP_FILES = {'messages.json'}


def ensure_dir(ftp: ftplib.FTP, path: str) -> None:
    parts = [p for p in path.split('/') if p]
    cur = ''
    for part in parts:
        cur += '/' + part
        try:
            ftp.cwd(cur)
        except ftplib.error_perm:
            ftp.mkd(cur)


def main() -> int:
    ftp = ftplib.FTP(HOST, timeout=60)
    ftp.login(USER, PASS)
    ftp.voidcmd('TYPE I')

    uploaded = 0
    for root, dirs, files in os.walk(LOCAL):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        rel = os.path.relpath(root, LOCAL).replace(os.sep, '/')
        remote_dir = REMOTE + ('' if rel == '.' else '/' + rel)
        ensure_dir(ftp, remote_dir)
        for name in sorted(files):
            if name in SKIP_FILES:
                continue
            local_path = os.path.join(root, name)
            remote_path = remote_dir + '/' + name
            with open(local_path, 'rb') as fh:
                ftp.storbinary('STOR ' + remote_path, fh)
            uploaded += 1
            print('uploaded', remote_path)

    ftp.quit()
    print('done, files =', uploaded)
    return 0


if __name__ == '__main__':
    sys.exit(main())
