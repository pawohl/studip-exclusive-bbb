"""Minimal BigBlueButton API mock for development and tests.

Implements create, join, end, getMeetings and isMeetingRunning with checksum
validation, plus /mock/calls (JSON log of API calls without checksums) and
/mock/reset. State is kept in memory. Not for production use.
"""

import hashlib
import json
import os
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qsl, urlsplit
from xml.sax.saxutils import escape

SECRET = os.environ.get("BBB_SECRET", "mock-secret")
NODE_NAME = os.environ.get("NODE_NAME", "mock")
PORT = int(os.environ.get("PORT", "8090"))
ALGORITHMS = {40: "sha1", 64: "sha256", 96: "sha384", 128: "sha512"}

lock = threading.Lock()
meetings = {}
calls = []


def xml_response(returncode, body="", message_key=None, message=None):
    parts = [f"<returncode>{returncode}</returncode>", body]
    if message_key:
        parts.append(f"<messageKey>{escape(message_key)}</messageKey>")
    if message:
        parts.append(f"<message>{escape(message)}</message>")
    return "<response>" + "".join(parts) + "</response>"


def meeting_xml(meeting):
    metadata = "".join(
        f"<{escape(k)}>{escape(v)}</{escape(k)}>" for k, v in meeting["metadata"].items()
    )
    return (
        "<meeting>"
        f"<meetingName>{escape(meeting['name'])}</meetingName>"
        f"<meetingID>{escape(meeting['id'])}</meetingID>"
        f"<createTime>{meeting['createTime']}</createTime>"
        "<running>true</running>"
        f"<participantCount>{len(meeting['participants'])}</participantCount>"
        f"<moderatorCount>{sum(1 for p in meeting['participants'] if p['role'] == 'MODERATOR')}</moderatorCount>"
        "<videoCount>0</videoCount>"
        f"<duration>{meeting['duration']}</duration>"
        f"<maxUsers>{meeting['maxParticipants']}</maxUsers>"
        f"<metadata>{metadata}</metadata>"
        "</meeting>"
    )


def expire_meetings():
    now = time.time()
    for meeting_id in list(meetings):
        meeting = meetings[meeting_id]
        if meeting["duration"] and now > meeting["createTime"] / 1000 + meeting["duration"] * 60:
            del meetings[meeting_id]


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print(f"[{NODE_NAME}] " + fmt % args, flush=True)

    def send(self, status, body, content_type="text/xml; charset=utf-8"):
        data = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        url = urlsplit(self.path)
        if url.path == "/mock/calls":
            with lock:
                return self.send(200, json.dumps(calls), "application/json")
        if url.path == "/mock/reset":
            with lock:
                meetings.clear()
                calls.clear()
            return self.send(200, "{}", "application/json")

        prefix = "/bigbluebutton/api/"
        if not url.path.startswith(prefix):
            return self.send(404, xml_response("FAILED", message_key="notFound"))
        call = url.path[len(prefix):]

        query = url.query
        if "&checksum=" in query:
            unsigned, checksum = query.rsplit("&checksum=", 1)
        elif query.startswith("checksum="):
            unsigned, checksum = "", query[len("checksum="):]
        else:
            return self.send(200, xml_response("FAILED", message_key="checksumError", message="missing checksum"))
        algorithm = ALGORITHMS.get(len(checksum))
        expected = hashlib.new(algorithm, (call + unsigned + SECRET).encode()).hexdigest() if algorithm else None
        if checksum != expected:
            return self.send(200, xml_response("FAILED", message_key="checksumError", message="checksum mismatch"))

        params = dict(parse_qsl(unsigned, keep_blank_values=True))
        with lock:
            expire_meetings()
            calls.append({"call": call, "params": params, "time": int(time.time())})
            handler = getattr(self, "api_" + call, None)
            if not handler:
                return self.send(200, xml_response("FAILED", message_key="unsupportedRequest"))
            return handler(params)

    def api_create(self, params):
        meeting_id = params.get("meetingID", "")
        if meeting_id in meetings:
            body = f"<meetingID>{escape(meeting_id)}</meetingID>"
            return self.send(200, xml_response("SUCCESS", body, "duplicateWarning", "already exists"))
        meetings[meeting_id] = {
            "id": meeting_id,
            "name": params.get("name", meeting_id),
            "createTime": int(time.time() * 1000),
            "duration": int(params.get("duration", "0") or 0),
            "maxParticipants": int(params.get("maxParticipants", "0") or 0),
            "metadata": {k[5:].lower(): v for k, v in params.items() if k.startswith("meta_")},
            "participants": [],
        }
        return self.send(200, xml_response("SUCCESS", f"<meetingID>{escape(meeting_id)}</meetingID>"))

    def api_join(self, params):
        meeting = meetings.get(params.get("meetingID", ""))
        if not meeting:
            return self.send(200, xml_response("FAILED", message_key="notFound"))
        if meeting["maxParticipants"] and len(meeting["participants"]) >= meeting["maxParticipants"]:
            return self.send(200, xml_response("FAILED", message_key="maxParticipantsReached"))
        role = params.get("role", "VIEWER").upper()
        meeting["participants"].append({"name": params.get("fullName", ""), "role": role})
        html = (
            "<!DOCTYPE html><html><head><meta charset='utf-8'><title>BBB mock</title></head><body>"
            f"<h1 id='joined'>{escape(NODE_NAME)}: {escape(meeting['name'])}</h1>"
            f"<p id='user'>{escape(params.get('fullName', ''))}</p>"
            f"<p id='role'>{escape(role)}</p></body></html>"
        )
        return self.send(200, html, "text/html; charset=utf-8")

    def api_end(self, params):
        if meetings.pop(params.get("meetingID", ""), None) is None:
            return self.send(200, xml_response("FAILED", message_key="notFound"))
        return self.send(200, xml_response("SUCCESS", "", "sentEndMeetingRequest"))

    def api_isMeetingRunning(self, params):
        running = "true" if params.get("meetingID", "") in meetings else "false"
        return self.send(200, xml_response("SUCCESS", f"<running>{running}</running>"))

    def api_getMeetings(self, params):
        if not meetings:
            return self.send(200, xml_response("SUCCESS", "<meetings/>", "noMeetings"))
        body = "<meetings>" + "".join(meeting_xml(m) for m in meetings.values()) + "</meetings>"
        return self.send(200, xml_response("SUCCESS", body))


if __name__ == "__main__":
    print(f"BBB mock '{NODE_NAME}' listening on :{PORT}", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
