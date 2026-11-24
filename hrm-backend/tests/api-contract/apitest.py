"""
End-to-end test of the Laravel backend against the Flutter app's Dart models.
Every response is checked against the schema extracted from lib/data/model/*.dart:
field types (String/int/bool/double/DateTime/List) and null-safety of
unguarded nested objects. Anything that would make the app crash is reported.
"""
import json, os, re, sys, sqlite3, datetime, requests
HERE = os.path.dirname(os.path.abspath(__file__))

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8000/api/V11'
DB = os.path.join(HERE, '..', '..', 'database', 'database.sqlite')
SCHEMA = json.load(open(os.path.join(HERE, 'schema.json')))
ERRPARSE = json.load(open(os.path.join(HERE, 'errparse.json')))
S = requests.Session()
results = []
TOKEN = {}

def check(value, node, path, errs):
    dart = (node.get('dart') or '').replace(' ', '')
    src = node.get('src', '')
    guarded = node.get('guarded')
    nullable = dart.endswith('?') or dart in ('', 'dynamic')
    if value is None:
        if 'DateTime.parse' in src and not guarded:
            errs.append(f'{path}: null but app does DateTime.parse without null check')
        elif 'fields' in node and not guarded:
            errs.append(f'{path}: null but app parses it as {node.get("class")} without null check')
        elif node.get('list') and not guarded:
            errs.append(f'{path}: null but app maps it as a list without null check')
        elif not nullable:
            errs.append(f'{path}: null for non-nullable {dart}')
        elif '.toString()' in src and 'int.parse' in src:
            errs.append(f'{path}: null passed to int.parse')
        return
    base = dart.rstrip('?')
    if 'DateTime.parse' in src:
        if not isinstance(value, str):
            errs.append(f'{path}: DateTime.parse needs a string, got {type(value).__name__}')
        else:
            try:
                datetime.datetime.fromisoformat(value.replace('Z', '+00:00')[:26] if 'T' in value else value)
            except Exception:
                errs.append(f'{path}: "{value}" is not ISO date')
        return
    if '.toString()' in src or 'toDouble' in src:
        if 'int.parse' in src and not re.fullmatch(r'-?\d+', str(value)):
            errs.append(f'{path}: int.parse("{value}") fails')
        return
    if node.get('list') or base.startswith('List'):
        if not isinstance(value, list):
            errs.append(f'{path}: expected list, got {type(value).__name__}')
            return
        inner = re.match(r'List<(\w+)\??>', base)
        for i, v in enumerate(value[:5]):
            if 'fields' in node:
                if not isinstance(v, dict):
                    errs.append(f'{path}[{i}]: expected object')
                else:
                    walk(v, node['fields'], f'{path}[{i}]', errs)
            elif inner and inner.group(1) in ('String', 'int', 'bool'):
                check(v, {'dart': inner.group(1) + '?'}, f'{path}[{i}]', errs)
        return
    if 'fields' in node:
        if not isinstance(value, dict):
            errs.append(f'{path}: expected object {node.get("class")}, got {type(value).__name__}')
        else:
            walk(value, node['fields'], path, errs)
        return
    t = {'String': str, 'int': int, 'bool': bool, 'double': (float,), 'num': (int, float)}.get(base)
    if t is None:
        return
    if base == 'int' and isinstance(value, bool):
        errs.append(f'{path}: bool given for int')
    elif base == 'double' and isinstance(value, int):
        errs.append(f'{path}: int given for double (Dart crash)')
    elif not isinstance(value, t):
        errs.append(f'{path}: expected {base}, got {type(value).__name__} ({str(value)[:30]})')

def walk(obj, fields, path, errs):
    for k, node in fields.items():
        if k not in obj:
            src = node.get('src', '')
            if 'int.parse' in src or 'double.parse' in src:
                errs.append(f'{path}.{k}: missing -> int.parse("null") crash')
            elif ('fields' in node or node.get('list') or 'DateTime.parse' in src) and not node.get('guarded'):
                errs.append(f'{path}.{k}: missing (app parses it without null check)')
            continue
        check(obj[k], node, f'{path}.{k}', errs)

def call(name, method, path, model=None, who='emp', expect=200, json_body=None, data=None, files=None, params=None):
    h = {'Accept': 'application/json'}
    if who and who in TOKEN:
        h['Authorization'] = 'Bearer ' + TOKEN[who]
    r = S.request(method, BASE + path, headers=h, json=json_body, data=data, files=files, params=params)
    errs = []
    try:
        body = r.json()
    except Exception:
        body = None
        errs.append(f'non-JSON response: {r.text[:200]}')
    if r.status_code != expect:
        errs.append(f'HTTP {r.status_code} (expected {expect}): {r.text[:300]}')
    if body is not None:
        for k in ('result', 'message'):
            if k not in body:
                errs.append(f'missing envelope key {k}')
        if model and r.status_code >= 300 and not any(path.startswith(e) for e in ERRPARSE):
            model = None  # app's error path only reads result/message
        if model:
            sch = SCHEMA.get(model)
            if not sch:
                errs.append(f'no schema for {model}')
            else:
                walk(body, sch['fields'], '$', errs)
    results.append((name, method, path, r.status_code, errs))
    return body or {}

def login(who, email):
    b = call(f'login {who}', 'POST', '/login', 'responseLoginFromJson', who=None,
             json_body={'email': email, 'password': '12345678', 'device_id': 'dev-' + who, 'device_info': 'pytest'})
    TOKEN[who] = b['data']['token']
    return b['data']['id']

png = b'\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82'
img = lambda field: {field: ('photo.png', png, 'image/png')}

# ------------------------------------------------------------------ run
call('login wrong password', 'POST', '/login', 'responseLoginFromJson', who=None, expect=401, json_body={'email': 'employee@hrm.test', 'password': 'x'})
call('login validation', 'POST', '/login', 'responseLoginFromJson', who=None, expect=422, json_body={'email': ''})
emp = login('emp', 'employee@hrm.test')
pm = login('pm', 'manager@hrm.test')
hr = login('hr', 'hr@hrm.test')
call('check-token', 'POST', '/check-token', 'responseCreateAppreciateFromJson', json_body={'token': TOKEN['emp']})
call('check-token invalid', 'POST', '/check-token', 'responseCreateAppreciateFromJson', who=None, expect=401, json_body={'token': 'bad'})
call('unauthenticated', 'GET', '/app/base-settings', who=None, expect=401)

call('base-settings', 'GET', '/app/base-settings', 'ResponseBaseSetting.fromJson')
call('home-screen menu', 'GET', '/app/home-screen', 'menuModelFromJson')
call('dashboard statistics', 'GET', '/dashboard/statistics', 'staticsModelFromJson')
call('upcoming events', 'POST', '/upcoming-events/get-list', 'eventHolidayModelFromJson', json_body={})
call('upcoming appointments', 'GET', '/appoinment/get-list', 'upcomingModelFromJson')
call('firebase token', 'POST', '/user/firebase-token', json_body={'user_id': emp, 'device_name': 'pixel', 'firebase_token': ''})
for slug in ('privacy-policy', 'terms-of-use', 'support-24-7'):
    call(f'contents {slug}', 'GET', f'/app/all-contents/{slug}', 'responseAllContentsFromJson')

# ---- attendance
call('attendance status (before)', 'POST', '/user/attendance/get-checkin-checkout-status', 'ResponseAttendanceStatus.fromJson', json_body={'user_id': emp})
today = datetime.date.today()
ci = {'user_id': emp, 'remote_mode_in': 0, 'date': f'{today.year}-{today.month:02d}-{today.day}', 'check_in': '10:05', 'check_in_location': 'Mohammadpur', 'check_in_latitude': '23.76', 'check_in_longitude': '90.36', 'city': 'Dhaka', 'country_code': 'BD', 'country': 'Bangladesh'}
b = call('check-in late (needs reason)', 'POST', '/user/attendance/check-in', 'ResponseCheckIn.fromJson', expect=422, json_body=ci)
assert b.get('error', {}).get('reason_status') == 'L', b
b = call('check-in with reason', 'POST', '/user/attendance/check-in', 'ResponseCheckIn.fromJson', json_body={**ci, 'reason': 'Traffic'})
att_id = b['data']['id']
call('check-in twice', 'POST', '/user/attendance/check-in', 'ResponseCheckIn.fromJson', expect=400, json_body={**ci, 'reason': 'x'})
call('attendance status (in)', 'POST', '/user/attendance/get-checkin-checkout-status', 'ResponseAttendanceStatus.fromJson', json_body={'user_id': emp})
call('late reason', 'POST', f'/user/attendance/late-in-reason/{att_id}', json_body={'type': 'in', 'reason': 'Rain'})
call('break start', 'POST', '/user/attendance/break-back/start', 'responseBreakFromJson', json_body={'time': '12:00'})
b = call('base-settings during break', 'GET', '/app/base-settings', 'ResponseBaseSetting.fromJson')
assert re.fullmatch(r'\d\d:\d\d:\d\d', b['data']['break_status']['diff_time']), b['data']['break_status']
call('break status', 'POST', '/user/attendance/break-status', 'responseBreakHistoryFromJson', json_body={'user_id': emp})
call('break back', 'POST', '/user/attendance/break-back/back', 'responseBreakFromJson', json_body={'time': '12:30'})
call('break back again', 'POST', '/user/attendance/break-back/back', 'responseBreakFromJson', expect=400, json_body={'time': '12:31'})
call('break history', 'POST', '/user/attendance/break-history', 'responseBreakReportFromJson', json_body={'date': str(today), 'user_id': emp})
co = {'user_id': emp, 'remote_mode_out': 0, 'date': str(today), 'check_out': '15:00', 'check_out_location': 'Office', 'check_out_latitude': '23.7', 'check_out_longitude': '90.3', 'city': 'Dhaka', 'country_code': 'BD', 'country': 'Bangladesh'}
b = call('check-out early (needs reason)', 'PATCH', f'/user/attendance/check-out/{att_id}', 'ResponseCheckOut.fromJson', expect=422, json_body=co)
assert b.get('error', {}).get('reason_status') == 'LE', b
call('check-out', 'PATCH', f'/user/attendance/check-out/{att_id}', 'ResponseCheckOut.fromJson', json_body={**co, 'reason': 'Doctor'})
call('attendance status (out)', 'POST', '/user/attendance/get-checkin-checkout-status', 'ResponseAttendanceStatus.fromJson', json_body={'user_id': emp})
call('live location', 'POST', '/user/attendance/live-location-store', 'responseLoginFromJson', json_body={'date': str(today), 'locations': [{'latitude': 23.7, 'longitude': 90.3, 'address': 'Dhaka', 'datetime': f'{today} 11:00:00'}]})

# ---- leave
call('leave summary', 'POST', '/user/leave/summary', 'responseLeaveSummaryFromJson', json_body={'user_id': emp})
call('leave available', 'POST', '/user/leave/available', 'responseLeaveTypeFromJson', json_body={'user_id': emp})
call('leave list', 'POST', '/user/leave/list/view', 'ResponseAllLeaveRequest.fromJson', json_body={'user_id': emp, 'month': today.strftime('%Y-%m')})
call('leave list all', 'POST', '/user/leave/list/view', 'ResponseAllLeaveRequest.fromJson', json_body={'user_id': emp})
lv = {'user_id': emp, 'assign_leave_id': 1, 'substitute_id': 5, 'apply_date': str(today), 'leave_from': str(today + datetime.timedelta(days=20)), 'leave_to': str(today + datetime.timedelta(days=21)), 'reason': 'Wedding'}
b = call('leave request', 'POST', '/user/leave/request', 'responseCreateLeaveRequestFromJson', data=lv, files=img('attachment_file'))
leave_id = b['data']['id']
call('leave request too many days', 'POST', '/user/leave/request', 'responseCreateLeaveRequestFromJson', expect=422, data={**lv, 'leave_to': str(today + datetime.timedelta(days=60))})
call('leave details', 'POST', f'/user/leave/details/{leave_id}', 'ResponseLeaveRequestDetails.fromJson', json_body={'user_id': emp})
call('leave update', 'POST', f'/user/leave/request/update/{leave_id}', data={'reason': 'Wedding (updated)', 'leave_from': lv['leave_from'], 'leave_to': lv['leave_from'], 'assign_leave_id': 1})
b2 = call('leave request 2', 'POST', '/user/leave/request', 'responseCreateLeaveRequestFromJson', data={**lv, 'leave_from': str(today + datetime.timedelta(days=30)), 'leave_to': str(today + datetime.timedelta(days=30))})
call('leave cancel', 'GET', f"/user/leave/request/cancel/{b2['data']['id']}")
call('approval list (manager)', 'POST', '/user/leave/approval/list/view', 'responseApprovalListFromJson', who='pm')
call('approval details (manager)', 'POST', f'/user/leave/details/{leave_id}', 'responseApprovalDetailsFromJson', who='pm', json_body={'user_id': emp})
call('approve by employee (forbidden)', 'GET', f'/user/leave/approval/status-change/{leave_id}/1', 'responseApprovalOrRejectFromJson', expect=403)
call('approve (manager)', 'GET', f'/user/leave/approval/status-change/{leave_id}/1', 'responseApprovalOrRejectFromJson', who='pm')

# ---- daily leave
call('daily leave store', 'POST', '/daily-leave/store', json_body={'leave_type': 'early_leave', 'approx_time': '16:00', 'reason': 'Bank work'})
call('daily leave summary', 'POST', '/daily-leave/leave-list', 'dailyLeaveSummaryResponseFromJson', json_body={'month': today.strftime('%Y-%m'), 'user_id': emp})
b = call('daily leave list', 'POST', '/daily-leave/staff-list-view', 'dailyLeaveDetailsResponseFromJson', json_body={'month': today.strftime('%Y-%m'), 'user_id': emp, 'leave_type': 'early_leave', 'leave_status': 'pending'})
dl = b['data']['data'][0]['id']
call('daily leave single', 'POST', '/daily-leave/single-view', 'dailyLeaveSingleViewResponseFromJson', json_body={'leave_id': dl})
call('daily leave approve (manager)', 'POST', '/daily-leave/approve-reject', who='pm', json_body={'leave_id': dl, 'leave_status': 'approved'})
call('daily leave list (hr, all)', 'POST', '/daily-leave/staff-list-view', 'dailyLeaveDetailsResponseFromJson', who='hr', json_body={'month': today.strftime('%Y-%m'), 'leave_status': 'Approved'})

# ---- visit
call('visit list', 'GET', '/visit/list', 'responseVisitListFromJson')
b = call('visit create', 'POST', '/visit/create', 'ResponseCreateVisit.fromJson', json_body={'date': str(today), 'title': 'Meet Baraka Group', 'description': 'Demo', 'user_id': emp})
vid = b['data']['id']
call('visit update', 'POST', '/visit/update', 'ResponseCreateVisit.fromJson', json_body={'id': vid, 'title': 'Meet Baraka Group (2)', 'description': 'Demo 2'})
call('visit show', 'GET', f'/visit/show/{vid}', 'responseVisitDetailsFromJson')
call('visit start', 'POST', '/visit/change-status', 'responseChangeStatusFromJson', json_body={'visit_id': vid, 'status': 'started', 'latitude': '23.7', 'longitude': '90.3', 'cancel_note': ''})
call('visit note', 'POST', '/visit/create-note', 'responseCreateVisitNoteFromJson', json_body={'visit_id': vid, 'note': 'On the way'})
call('visit image', 'POST', '/visit/image-upload', 'responseImageVisitFromJson', data={'id': vid}, files=img('visit_image'))
call('visit reach', 'POST', '/visit/change-status', 'responseChangeStatusFromJson', json_body={'visit_id': vid, 'status': 'reached', 'latitude': '23.7', 'longitude': '90.3'})
call('visit show (full)', 'GET', f'/visit/show/{vid}', 'responseVisitDetailsFromJson')
call('visit reschedule', 'POST', '/visit/create-schedule', 'responseVerificationCodeFromJson', json_body={'visit_id': vid, 'date': str(today + datetime.timedelta(days=1)), 'latitude': '1', 'longitude': '2', 'note': 'Client busy'})
call('visit cancel', 'POST', '/visit/change-status', 'responseVerificationCodeFromJson', json_body={'visit_id': vid, 'status': 'cancelled', 'latitude': '1', 'longitude': '2', 'cancel_note': 'Cancelled by client'})
call('visit history', 'POST', '/visit/history', 'responseVisitHistoryFromJson', json_body={'month': today.strftime('%Y-%m')})

# ---- meeting / appointment / conference
call('meeting list', 'POST', '/meeting', 'responseMeetingListFromJson', json_body={'month': today.strftime('%Y-%m')})
call('meeting create', 'POST', '/meeting/create?', data={'title': 'Design review', 'description': 'Review', 'participants': '5,6', 'date': str(today), 'location': 'Room 1', 'start_at': '14:00', 'end_at': '15:00'}, files=img('attachment_file'))
b = call('meeting list (after)', 'POST', '/meeting', 'responseMeetingListFromJson', json_body={'month': today.strftime('%Y-%m')})
mid = b['data']['items'][0]['id']
call('meeting show', 'GET', f'/meeting/show/{mid}', 'responseMeetingDetailsFromJson')
call('appointment create', 'POST', '/appoinment/create', data={'title': 'Laptop request', 'description': 'Need new laptop', 'appoinment_with': hr, 'date': str(today + datetime.timedelta(days=1)), 'location': 'HR', 'appoinment_start_at': '10:00', 'appoinment_end_at': '10:30'})
b = call('appointment list', 'POST', '/appoinment/get-list', 'responseMeetingListFromJson', json_body={'month': today.strftime('%Y-%m')})
aid = b['data']['items'][0]['id'] if b['data']['items'] else 1
call('appointment details', 'GET', f'/appoinment/details/{aid}', 'appointmentDetailsModelFromJson')
call('conference list', 'GET', '/conference/my-meeting', 'conferenceListResponseFromJson')

# ---- notice / support / notifications
call('notice add (employee forbidden)', 'POST', '/notice/add', 'responseNoticeCreateFromJson', expect=403, data={'subject': 'x'})
call('notice add (hr)', 'POST', '/notice/add', 'responseNoticeCreateFromJson', who='hr', data={'subject': 'Eid bonus', 'date': str(today), 'department_id': '', 'description': 'Bonus with salary'}, files=img('file'))
b = call('notice list', 'POST', '/notice/list', 'responseNoticeListFromJson', json_body={})
call('notice show', 'GET', f"/notice/show/{b['data']['notices']['data'][0]['id']}", 'responseNoticeDetailsFromJson')
call('notice clear', 'GET', '/notice/clear', 'responseClearNotificationFromJson')
call('support add', 'POST', '/support-ticket/add', 'responseSupportCreateFromJson', data={'subject': 'Laptop slow', 'description': 'Needs RAM', 'priority_id': 1}, files=img('attachment_file'))
b = call('support list', 'POST', '/support-ticket/list', 'responseSupportTicketListFromJson', json_body={'type': 'open', 'month': today.strftime('%Y-%m')})
call('support show', 'GET', f"/support-ticket/show/{b['data']['data'][0]['id']}", 'responseSupportDetailsFromJson')
b = call('notifications', 'GET', '/user/notification', 'notificationModelFromJson')
nid = b['data']['notifications'][0]['id'] if b['data']['notifications'] else 'x'
call('read notification', 'GET', f'/user/read-notification?notification_id={nid}', 'readNotificationFromJson')
call('notifications (hr)', 'GET', '/user/notification', 'notificationModelFromJson', who='hr')

# ---- profile & phonebook
for slug, model in [('official', 'responseOfficialInfoFromJson'), ('personal', 'responsePersonalFromJson'), ('financial', 'responseFinancialFromJson'), ('emergency', 'responseEmergencyFromJson')]:
    call(f'profile {slug}', 'POST', f'/user/profile/{slug}', model, json_body={'user_id': emp})
call('update official', 'POST', '/user/profile/update/official', 'responseUpdateOfficalInfoFromJson', json_body={'user_id': emp, 'name': 'Tanvir Hasan', 'email': 'employee@hrm.test', 'department_id': 3, 'designation_id': 4, 'manager_id': pm, 'joining_date': '2024-01-01', 'employee_type': 'Permanent', 'employee_id': 'EMP-010', 'grade': 'A'})
call('update personal', 'POST', '/user/profile/update/personal', 'responseEditPersonalInfoFromJson', data={'user_id': emp, 'gender': 'Male', 'phone': '01711111111', 'birth_date': '1998-05-10', 'address': 'Dhaka', 'nationality': 'Bangladeshi', 'nid_card_number': '123', 'blood_group': 'A+', 'facebook_link': 'fb', 'linkedin_link': 'li', 'instagram_link': 'ig'}, files=img('nid_file'))
call('update financial', 'POST', '/user/profile/update/financial', 'responseEditFinancialFromJson', json_body={'user_id': emp, 'tin': '111', 'bank_name': 'BRAC Bank', 'bank_account': '1501'})
call('update emergency', 'POST', '/user/profile/update/emergency', 'responseEditEmergencyFromJson', json_body={'user_id': emp, 'emergency_name': 'Father', 'emergency_mobile_number': '01800000000', 'emergency_mobile_relationship': 'Father'})
call('avatar update', 'POST', '/user/avatar-update?avatar', 'responseProfileImageFromJson', data={'user_id': emp}, files=img('avatar'))
call('appreciate create', 'POST', '/appreciate/create', 'responseCreateAppreciateFromJson', who='pm', json_body={'appreciate_to': emp, 'message': 'Well done'})
call('appreciate list', 'POST', '/user/profile/appreciates', 'responseAppreciateListFromJson', json_body={'user_id': emp})
call('all users', 'GET', '/app/get-all-users/?keywords=', 'responseAllUserFromJson')
call('all users by designation', 'GET', '/app/get-all-users/4?keywords=Tan', 'responseAllUserFromJson')
call('user details', 'GET', f'/user/details/{emp}', 'phonebookDetailsModelFromJson')
call('user search', 'GET', '/user/search/Sad', 'responseUserSearchFromJson')
call('departments', 'GET', '/app/get-department', 'responseDepartmentFromJson')
call('designations', 'GET', '/app/get-designation', 'responseDesignationFromJson')
call('employment types', 'GET', '/app/get-employment-type', 'responseEmploymentTypeFromJson')
call('blood groups', 'GET', '/app/get-blood-group', 'responseBloodGroupFromJson')
call('managers', 'GET', '/app/get-manager', 'responseManagerListFromJson')

# ---- expense
call('expense categories', 'GET', '/accounts/expense/category-list', 'ExpenseCategoryModel.fromJson')
call('expense add', 'POST', '/accounts/expense/add', data={'category': 1, 'date': str(today), 'amount': '250', 'description': 'CNG fare', 'reference': 'R1'}, files=img('attachment'))
b = call('expense legacy list', 'POST', '/accounts/expense/list', 'ResponseExpenseList.fromJson', json_body={'date': today.strftime('%Y-%m'), 'payment': '', 'status': ''})
call('expense legacy view', 'GET', f"/accounts/expense/view/{b['data'][0]['id']}", 'ResponseExpenseDetail.fromJson')
b = call('expense list', 'POST', '/expense/list', 'ExpenseListModel.fromJson', json_body={'month': today.strftime('%Y-%m')})
eid = b['data']['expense_list']['data'][0]['id']
call('expense single', 'GET', f'/expense/single-expense/{eid}', 'ExpenseListDetailsModel.fromJson')
call('expense update', 'POST', f'/expense/update/{eid}', data={'remarks': 'CNG fare (edited)', 'amount': '260'})
call('expense add (to delete)', 'POST', '/accounts/expense/add', data={'category': 2, 'date': str(today), 'amount': '99'})
b = call('expense list 2', 'POST', '/expense/list', 'ExpenseListModel.fromJson', json_body={'month': today.strftime('%Y-%m')})
call('expense delete', 'DELETE', f"/expense/delete/{b['data']['expense_list']['data'][0]['id']}")
call('expense send claim', 'POST', '/expense/send-claim', json_body={'remarks': 'November claim'})
call('expense send claim (empty)', 'POST', '/expense/send-claim', expect=400, json_body={'remarks': 'again'})
b = call('claim history', 'POST', '/expense/claim-history', 'ExpenseClaimModel.fromJson', json_body={'month': today.strftime('%Y-%m')})
call('claim details', 'POST', f"/expense/claim-details/{b['data']['data'][0]['id']}", 'ExpenseClaimDetailsModel.fromJson', data='')
con = sqlite3.connect(DB); con.execute("update expense_claims set paid_amount=payable_amount, due_amount=0"); con.execute("insert into expense_payments(expense_claim_id,payment_date,amount,created_at,updated_at) select id, date('now'), payable_amount, datetime('now'), datetime('now') from expense_claims"); con.commit()
call('payment history', 'POST', '/expense/payment-history', 'PaymentHistoryModel.fromJson', json_body={'month': today.strftime('%Y-%m')})

# ---- payroll
b = call('payslip list', 'POST', '/report/payslip/list', 'responsePayrollFromJson', json_body={'year': today.year if today.month > 1 else today.year - 1})
link = b['data'][0]['payslip_link'] if b.get('data') else None
if link:
    r = requests.get(link)
    results.append(('payslip pdf download', 'GET', 'signed link', r.status_code, [] if r.status_code == 200 and r.content.startswith(b'%PDF') else ['not a PDF']))
    open(os.path.join(HERE, 'payslip-sample.pdf'), 'wb').write(r.content)
    r = requests.get(link.split('?')[0])
    results.append(('payslip pdf unsigned (blocked)', 'GET', 'unsigned link', r.status_code, [] if r.status_code == 403 else ['unsigned link should be 403']))

# ---- tasks
call('task dashboard', 'GET', '/tasks', 'taskDashboardModelFromJson')
call('task list in progress', 'GET', '/tasks/list?keyword=&status=26&priority=', 'taskListModelFromJson')
call('task list complete', 'GET', '/tasks/list?keyword=Land&status=27&priority=2', 'taskListModelFromJson', who='pm')
call('task details', 'GET', '/tasks/1', 'taskDetailsModelFromJson')
call('task complete', 'GET', '/tasks/change-status?task_id=1&type=1&change_to=27', 'taskDetailsModelFromJson')

# ---- reports
call('report monthly', 'POST', f'/report/attendance/particular-month/{emp}', 'AttendanceReport.fromJson', json_body={'month': today.strftime('%Y-%m')})
call('report daily', 'POST', '/report/attendance/particular-date', 'AttendanceDailyReport.fromJson', json_body={'date': str(today), 'user_id': emp})
call('report daily (absent day)', 'POST', '/report/attendance/particular-date', 'AttendanceDailyReport.fromJson', json_body={'date': str(today + datetime.timedelta(days=2)), 'user_id': emp})
call('report date summary (hr)', 'POST', '/report/attendance/date-summary', 'reportAttendanceRepositoryFromJson', who='hr', json_body={'date': str(today - datetime.timedelta(days=1))})
for t in ('present', 'absent', 'late_in', 'on_time_in', 'leave', 'left_timely'):
    call(f'report summary-to-list {t}', 'POST', '/report/attendance/summary-to-list', 'reportAttendanceListResponseFromJson', who='hr', json_body={'type': t, 'date': str(today - datetime.timedelta(days=1))})
call('report break summary', 'POST', '/report/break/date-summary', 'breakReportSummeryResponseFromJson', who='hr', json_body={'date': str(today)})
call('report user break history', 'POST', '/report/break/user-break-history?page=1', 'breakReportListResponseFromJson', who='hr', json_body={'date': str(today), 'user_id': emp})
call('report leave summary', 'POST', '/report/leave/date-summary', 'reportLeaveSummeryResponseFromJson', who='hr', json_body={'date': str(today + datetime.timedelta(days=20))})
call('report leave list', 'POST', '/report/leave/date-wise-leave', 'leaveReportDetailsResponseFromJson', who='hr', json_body={'date': str(today + datetime.timedelta(days=20)), 'leave_type': 1})

# ---- passwords
call('password update wrong', 'POST', '/user/password-update', 'responseChangePasswordProfileFromJson', expect=422, json_body={'user_id': emp, 'current_password': 'bad', 'password': 'newpass1', 'password_confirmation': 'newpass1'})
call('password update', 'POST', '/user/password-update', 'responseChangePasswordProfileFromJson', json_body={'user_id': emp, 'current_password': '12345678', 'password': 'newpass1', 'password_confirmation': 'newpass1'})
call('reset password code', 'POST', '/reset-password', 'responseVerificationCodeFromJson', who=None, json_body={'email': 'employee@hrm.test'})
con = sqlite3.connect(DB)
con.execute("update password_reset_tokens set token=? where email='employee@hrm.test'", ('$2y$12$' + 'x' * 53,)); con.commit()
call('reset password bad code', 'POST', '/change-password', 'responseChangePasswordFromJson', who=None, expect=422, json_body={'email': 'employee@hrm.test', 'code': '000000', 'password': '12345678', 'password_confirmation': '12345678'})
call('logout', 'POST', '/logout')
call('token revoked', 'GET', '/app/base-settings', expect=401)

# ------------------------------------------------------------------ report
bad = [r for r in results if r[4]]
for name, m, p, code, errs in results:
    print(('FAIL ' if errs else 'ok   ') + f'{code} {m:6} {p:55} {name}')
    for e in errs[:8]:
        print('       -', e)
print(f'\n{len(results)} checks, {len(results) - len(bad)} passed, {len(bad)} failed')
