#!/usr/bin/env python3
"""MemStore 客户端示例：处理主机前置 JS 校验 + 注册/登录/上传/列表/下载。

用法：
  export MEMSTORE_USER=my-agent MEMSTORE_PASS=secret123
  python3 client.py register --service "My AI Service" --caller-id svc-aicode-001
  # 注册返回的 keycode 只出现一次，请立即保存；之后登录必须提供
  export MEMSTORE_KEYCODE=<注册返回的 keycode>
  python3 client.py login
  python3 client.py upload --file ./notes.md --category session --tags agent,summary
  python3 client.py list --category session
  python3 client.py download --uuid <uuid> --out ./out.md

说明：该主机在应用前有一层 JS 校验（写入 __test cookie），挑战值与请求 URL 绑定。
脚本每次调用前先请求目标 URL，取回挑战页，用 openssl 解密出 cookie 再带 Cookie 重发请求。
"""
import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('MEMSTORE_BASE', 'http://wfwm.uuk.pp.ua')
API = BASE + '/api/index.php'
UA = 'Mozilla/5.0 (compatible; MemStoreClient/1.0)'


def solve_cookie(url: str = '') -> str:
    """请求目标 URL 拿到挑战页，解出 __test cookie。挑战值与 URL 绑定。"""
    req = urllib.request.Request(url or (BASE + '/'), headers={'User-Agent': UA})
    with urllib.request.urlopen(req, timeout=30) as resp:
        html = resp.read().decode('utf-8', 'replace')
    if '__test' not in html:
        return ''
    vals = re.findall(r'toNumbers\("([0-9a-f]+)"\)', html)
    if len(vals) < 3:
        return ''
    key, iv, ct = vals[0], vals[1], vals[2]
    proc = subprocess.run(
        ['openssl', 'enc', '-d', '-aes-128-cbc', '-K', key, '-iv', iv, '-nopad'],
        input=bytes.fromhex(ct), capture_output=True,
    )
    if proc.returncode != 0:
        return ''
    return proc.stdout.hex()


def get_cookie(url: str) -> str:
    """挑战值按 URL 下发，必须针对目标 URL 现解，不能跨 URL 复用。"""
    return solve_cookie(url)


def request(method: str, action: str, *, token='', data=None, files=None, params=None, raw=False, multipart=False):
    url = API + '?action=' + action
    if params:
        url += '&' + urllib.parse.urlencode(params)

    headers = {'User-Agent': UA}
    if token:
        headers['X-Api-Token'] = token

    body = None
    if multipart or files:
        boundary = '----MemStore' + os.urandom(8).hex()
        parts = []
        for name, value in (data or {}).items():
            parts.append(
                f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
            )
        for name, (filename, content) in (files or {}).items():
            parts.append(
                f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\n'
                f'Content-Type: application/octet-stream\r\n\r\n'.encode() + content + b'\r\n'
            )
        parts.append(f'--{boundary}--\r\n'.encode())
        body = b''.join(parts)
        headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
    elif data is not None:
        body = json.dumps(data).encode()
        headers['Content-Type'] = 'application/json'

    req = urllib.request.Request(url, data=body, headers=headers, method=method)

    def call():
        with urllib.request.urlopen(req, timeout=60) as resp:
            payload = resp.read()
            return resp.status, (payload if raw else json.loads(payload or b'{}')), resp.headers

    # 先探测是否需要前置校验，按当前 URL 现解 cookie
    cookie = solve_cookie(url)
    if cookie:
        headers['Cookie'] = '__test=' + cookie
        req = urllib.request.Request(url, data=body, headers=headers, method=method)

    try:
        return call()
    except urllib.error.HTTPError as e:
        payload = e.read()
        try:
            return e.code, json.loads(payload or b'{}'), e.headers
        except json.JSONDecodeError:
            return e.code, {'raw': payload.decode('utf-8', 'replace')}, e.headers


def need_creds():
    user = os.environ.get('MEMSTORE_USER')
    pwd = os.environ.get('MEMSTORE_PASS')
    if not user or not pwd:
        sys.exit('请先设置环境变量 MEMSTORE_USER 与 MEMSTORE_PASS')
    return user, pwd


def need_keycode():
    keycode = os.environ.get('MEMSTORE_KEYCODE', '').strip()
    if not keycode:
        sys.exit('请先设置环境变量 MEMSTORE_KEYCODE（注册时返回的 128 位校验码）')
    return keycode


def cmd_register(args):
    user, pwd = need_creds()
    status, body, _ = request('POST', 'register', data={
        'username': user, 'password': pwd, 'service_name': args.service, 'I': args.caller_id,
    })
    print(status, json.dumps(body, ensure_ascii=False, indent=2))
    if status == 201:
        print('\n保存好 keycode（只返回一次，登录必填）：', body.get('keycode'))
        print('保存好 token：', body.get('token'))


def cmd_login(args):
    user, pwd = need_creds()
    keycode = need_keycode()
    status, body, _ = request('POST', 'login', data={
        'username': user, 'password': pwd, 'keycode': keycode,
    })
    print(status, json.dumps(body, ensure_ascii=False, indent=2))


def cmd_upload(args):
    token = os.environ.get('MEMSTORE_TOKEN') or ''
    if not token:
        sys.exit('请先设置 MEMSTORE_TOKEN')
    data = {'category': args.category, 'title': args.title or '', 'tags': args.tags or ''}
    if args.text is not None:
        data['source'] = 'text'
        data['content'] = args.text
        data['filename'] = args.filename or 'note.txt'
        status, body, _ = request('POST', 'upload', token=token, data=data, multipart=True)
    else:
        content = open(args.file, 'rb').read()
        status, body, _ = request(
            'POST', 'upload', token=token, data=data,
            files={'file': (os.path.basename(args.file), content)},
        )
    print(status, json.dumps(body, ensure_ascii=False, indent=2))


def cmd_list(args):
    token = os.environ.get('MEMSTORE_TOKEN') or ''
    if not token:
        sys.exit('请先设置 MEMSTORE_TOKEN')
    params = {'limit': args.limit, 'offset': args.offset}
    if args.category:
        params['category'] = args.category
    status, body, _ = request('GET', 'list', token=token, params=params)
    print(status, json.dumps(body, ensure_ascii=False, indent=2))


def cmd_download(args):
    token = os.environ.get('MEMSTORE_TOKEN') or ''
    if not token:
        sys.exit('请先设置 MEMSTORE_TOKEN')
    status, payload, headers = request('GET', 'file', token=token, params={'uuid': args.uuid}, raw=True)
    if status != 200:
        print(status, payload.decode('utf-8', 'replace'))
        return
    with open(args.out, 'wb') as fh:
        fh.write(payload)
    print('已保存', args.out, len(payload), '字节，类型', headers.get('Content-Type'))


def main():
    parser = argparse.ArgumentParser(description='MemStore 客户端')
    sub = parser.add_subparsers(dest='cmd', required=True)

    p = sub.add_parser('register')
    p.add_argument('--service', required=True, help='调用方服务名称')
    p.add_argument('--caller-id', required=True, help='字段 I：调用方标识，1-64 位')
    p.set_defaults(func=cmd_register)

    p = sub.add_parser('login')
    p.set_defaults(func=cmd_login)

    p = sub.add_parser('upload')
    p.add_argument('--file')
    p.add_argument('--text')
    p.add_argument('--filename')
    p.add_argument('--category', default='general')
    p.add_argument('--title')
    p.add_argument('--tags')
    p.set_defaults(func=cmd_upload)

    p = sub.add_parser('list')
    p.add_argument('--category')
    p.add_argument('--limit', type=int, default=20)
    p.add_argument('--offset', type=int, default=0)
    p.set_defaults(func=cmd_list)

    p = sub.add_parser('download')
    p.add_argument('--uuid', required=True)
    p.add_argument('--out', required=True)
    p.set_defaults(func=cmd_download)

    args = parser.parse_args()
    args.func(args)


if __name__ == '__main__':
    main()
