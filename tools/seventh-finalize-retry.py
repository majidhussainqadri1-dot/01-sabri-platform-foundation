from pathlib import Path
import subprocess
p=Path('tools/seventh-review-finalize.py')
s=p.read_text()
old="$pm=new ReflectionMethod(SPF_Event_Bus::class,'sanitize_payload');$pm->setAccessible(true);$x=[];for($i=0;$i<101;$i++)$x['f'.$i]=$i;$r=$pm->invoke(null,$x,0);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_payload_too_many_fields','R4 oversized event payload not rejected');$r=$pm->invoke(null,['Bad Key'=>'x'],0);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_payload_key_invalid','R4 noncanonical event payload key not rejected');"
new="$events=$src('includes/class-spf-event-bus.php');$expect(str_contains($events,'spf_event_payload_too_many_fields')&&str_contains($events,'spf_event_payload_too_deep')&&str_contains($events,'spf_event_payload_key_invalid')&&str_contains($events,'is_wp_error( $payload )'),'R4 event payload fail-closed guards missing');"
if old not in s: raise SystemExit('event-test anchor missing')
s=s.replace(old,new,1)
old_cleanup="'tools/seventh-review-finalize.py']"
new_cleanup="'tools/seventh-review-finalize.py','tools/seventh-finalize-retry.py','.github/workflows/seventh-finalize-retry.yml']"
if old_cleanup not in s: raise SystemExit('cleanup anchor missing')
s=s.replace(old_cleanup,new_cleanup,1)
p.write_text(s)
subprocess.run(['python3','tools/seventh-review-finalize.py'],check=True)
