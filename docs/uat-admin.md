# Midwestern Learning — Administrator acceptance test

Run this with an admin account (one that can manage users and content). It walks the setup an administrator actually does, in the order that makes sense: reference data first, then the custom fields, then people, then content and the rest. Each row is one action and what you should see — tick the last column, and note anything that doesn't match.

**Before you start**
- An admin login for the environment you were given, with the institution code.
- A couple of existing user accounts to point at as a line manager / line executive when you create someone.
- Chrome or Edge.

Sample person used below (use your own if you prefer): **Chidi Okeke**, `chidi.okeke@midwestern`, Employee ID `MW-0042`, Department *Retail Operations*, Job Title *Store Supervisor*, Job Grade *G5*, Work Location *Lagos HQ*.

## 1. Signing in

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 1.1 | Sign in with the admin account (institution code + email + password). | You reach the app. The admin area is available from the top-right menu (Administrator) or at **Administration**. | |
| 1.2 | Open **Administration**. | The admin dashboard loads. | |

## 2. Reference data

The user form's Department, Job Title, Job Grade and Work Location come from here, so check they exist first.

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 2.1 | From the admin **Modules** menu, open **Reference data**. | Lists for branches, departments, teams, levels (grades) and job titles load. | |
| 2.2 | Confirm Midwestern's departments, job titles, grades and work locations are present; add one if a value you need for the test is missing (e.g. department *Retail Operations*). | New entries save and immediately appear in the list. | |

## 3. Custom profile fields

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 3.1 | From the admin **Modules** menu, open **Custom fields**. | The Custom Profile Fields screen loads. Midwestern's seeded fields — Division, Organization / Subsidiary, Entity — are listed. | |
| 3.2 | Click **Add field**. Enter label *Region*, type **Dropdown**, add options *North*, *South*, *East*, *West*, tick **Required**, leave Active on, and **Save**. | The field is added to the list with a `dropdown` tag and a Required marker. | |
| 3.3 | Add a second field: label *Confirmed staff*, type **Yes / No**, not required. Save. | It appears in the list. | |
| 3.4 | Edit the *Region* field and change its label to *Region / Zone*. | The label updates. The **Type** control is locked (greyed out) with a note that the type can't change once a field exists. | |
| 3.5 | Edit a field, untick **Active**, save, then edit again and tick it back on. | Inactive fields show dimmed with an "inactive" badge; reactivating restores them. | |
| 3.6 | Try to add a dropdown with no options and save. | It is refused with a message to add at least one option. | |

## 4. Creating and editing people

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 4.1 | Open **Users**, then **Add user**. | The create form opens with Personal Information and Organization sections. | |
| 4.2 | Fill Personal Information: first/last name, email, phone, **Gender**, **Employee ID** (`MW-0042`). | All fields accept input. | |
| 4.3 | Fill Organization: **Department**, **Job Title**, **Job Grade**, **Work Location** (from the reference-data lists), **Line Manager** and **Line Executive** (pick existing people), **Hire Date**, and at least one role. | The pickers are populated from reference data and from existing users. | |
| 4.4 | In **Additional Information**, fill the custom fields — pick a *Region / Zone*, set *Confirmed staff*, fill Division / Subsidiary / Entity. Leave *Region / Zone* **blank** on purpose and try to save. | Saving is refused because *Region / Zone* is required. | |
| 4.5 | Set *Region / Zone* and save. | The user is created. A new account with no password is left *invited* and sent an invitation; if you set a password, the account is active. | |
| 4.6 | Open the new user's detail page. | Personal, Organization, **Additional Information** and Account cards all show, with everything you entered (Employee ID, both reporting lines, the custom fields). | |
| 4.7 | Edit the user, change only the phone number, and save. | The save succeeds and the custom-field values are untouched — they are not wiped by an unrelated edit. | |
| 4.8 | Deactivate the *Confirmed staff* custom field (step 3.5), then edit this user again (change anything) and save; reopen their detail. | The previously stored *Confirmed staff* value is still there — deactivating a field does not erase data already captured. | |
| 4.9 | If you use bulk import: open **Users → Import**, upload a small spreadsheet, review the preview, and commit. | The preview shows which rows will be created/updated and why; committing creates them. | |

## 5. Content and courses

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 5.1 | Open **Media** (Media & Learning Content) and upload a file. | The upload completes and the item appears in the library. | |
| 5.2 | Upload a SCORM package (a .zip). Wait on the screen while it unpacks. | Progress shows while it unpacks, then a summary (ready to play, SCORM version, launch file). A large package (hundreds of MB) uploads without a "could not reach the server" error. | |
| 5.3 | Preview the SCORM item. | It plays in the browser rather than downloading as a zip. | |
| 5.4 | Open **Courses**, create a new course, then open its **Content** and add a module with a lesson (attach the media/SCORM). Publish it. | The course saves, content is added, and it can be published. | |

## 6. Enrolment, live training, competency, reports, settings

| # | Do this | You should see | Pass |
|---|---------|----------------|------|
| 6.1 | Open **Enrollment** and enrol the new user (or a department) on the course. | The enrolment is created and the learner would see the course under Learning. | |
| 6.2 | From **Modules**, open **Live Training** and schedule a class. | The class is created with its date/time and provider. | |
| 6.3 | From **Modules**, open **Competency**: review the dictionary and job profiles, and create a cycle. | Creating a cycle opens the exercise and starts draft assessments for people who hold a job profile. | |
| 6.4 | Open **Reports**. | Tenant reports load with Midwestern's data. | |
| 6.5 | Open **Settings** and review branding / organisation settings. | Settings load; a change (e.g. a logo or a toggle) saves. | |

**Tester:** _______________  **Date:** __________  **Environment:** __________

Write anything that failed or looked wrong next to the step number.
