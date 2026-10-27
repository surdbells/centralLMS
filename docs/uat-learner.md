# Midwestern Learning — Learner acceptance test

Run this as an ordinary staff member would — a learner account, no admin rights. Work top to bottom; each row is one thing to do and what you should see. Tick the last column, and if something doesn't match, write what happened instead in the margin.

**Before you start**
- You need the learner test account the admin set up for you (email + temporary password, or the invitation link).
- Know your institution code — Midwestern's sign-in asks for it. If you came in by invitation link, the code is already filled.
- Do this on the environment you were pointed at (staging or live), in Chrome or Edge.
- If the account is brand new, the first thing it will ask for is a new password. Set one and keep it for the rest of the run.

Test values used below (swap in whatever you were actually given): email `amina.yusuf@midwestern`, password you set at first sign-in.

## 1. Signing in

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 1.1 | Open the site. If asked, enter the institution code, then your email and password. | You land on your dashboard. Your name and photo (or initial) show top-right. | |
| 1.2 | Open the language switcher and change it to French, then to Arabic, then back to English. | Menus and labels change language each time. Arabic stays left-to-right (it is translated, not mirrored). | |
| 1.3 | Sign out from the top-right menu, then sign back in. | Sign-out returns you to the login page; signing back in works with the same password. | |

## 2. Your dashboard and profile

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 2.1 | On **Dashboard**, look over the cards (courses in progress, anything due, progress). | The dashboard loads with your own figures, not placeholders. | |
| 2.2 | Open **My Account** from the top-right menu. On the **Profile** tab, read the Personal Information, Organization, and Additional Information cards. | Your name, email, gender and language show. Organization shows your Department, Job Title, Job Grade, Work Location, Line Manager, Line Executive and Hire Date (whatever the admin set — blanks show a dash). Additional Information lists any custom fields Midwestern added (e.g. Division, Subsidiary, Entity) as read-only. | |
| 2.3 | Click **Edit** on Personal Information. Change your phone, pick a Gender, change the language, then **Save Changes**. | It saves and the card shows the new values. The Organization and Additional Information cards are not editable here — those are set by your administrator. | |
| 2.4 | Go to the **Security** tab and change your password (current password, then a new one twice). | The strength meter reacts as you type; saving confirms the change. Sign out and back in with the new password to be sure. | |
| 2.5 | Still on **Security**, look at Recent Login Activity and end one session if more than one is listed. | Your sign-ins are listed with device and time; ending a session removes it from the list. | |
| 2.6 | Go to the **Preferences** tab, toggle the privacy/marketing/data switches and **Save Preferences**. | The choices save and stay set when you return to the tab. | |

## 3. Taking a course

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 3.1 | Open **Learning** from the top menu. | You see courses available to you and anything you are already enrolled on. | |
| 3.2 | Open a course and start the first lesson. | The lesson opens and plays — video, document or SCORM content as the case may be. | |
| 3.3 | If the course has SCORM content, let it load fully and move through a slide or two. | The SCORM content loads and runs; your position is remembered when you leave and come back. | |
| 3.4 | Leave the course part-way, return to **Learning**, and reopen it. | It resumes where you left off; progress is not reset. | |
| 3.5 | Finish a short course (or the one assigned for testing). | On completion the course is marked complete and, if it awards one, a certificate becomes available. | |

## 4. Certificates

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 4.1 | Open **My Certificates** from the top-right menu. | Any certificates you have earned are listed. | |
| 4.2 | Open one and download / print it. | The certificate renders with your name and the course; the download opens cleanly. | |

## 5. Live training

Skip this section if no class is scheduled for your test account.

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 5.1 | Open **Live Training** from the top menu. | Scheduled live classes are listed with their dates. | |
| 5.2 | For a class that is live now, join the room. | The room opens; you can see/hear the session, use chat, and raise your hand. | |

## 6. The rest of the menus

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 6.1 | Open **Skills** from the top menu. | Your competency tabs load (your levels against your job profile, gaps, and so on). | |
| 6.2 | From the **Modules** menu, open **Self-Assessment**. | Your self-assessment for the current cycle opens and can be filled in and submitted. | |
| 6.3 | From **Modules**, open **Learning Paths**. | Any curated paths assigned to you are listed. | |
| 6.4 | From **Modules**, open **Digital Library**. | Files shared with your audience are listed; you can open one (download is intentionally not offered). | |
| 6.5 | Open **Reports** from the top menu. | Your own performance figures load. | |
| 6.6 | Open **Messages**, then the notifications bell. | Messages and notifications load without error. | |

**Tester:** _______________  **Date:** __________  **Environment:** __________

Note anything that failed, looked wrong, or was confusing, next to the step number.
