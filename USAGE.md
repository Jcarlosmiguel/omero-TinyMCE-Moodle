# Using "Embed an OMERO slide" - a walkthrough for teachers

This is for anyone with editing rights in a course who wants to embed an
OMERO microscopy slide into a Label, Page, or Book chapter. It assumes
someone else has already configured the OMERO server itself (see
[README.md](README.md) if that's you instead) - but **your own subject
account is something you add yourself**, no admin or manager needed (see
Step 1 below).

## Finding the tool

1. Go into your course.
2. Look at the row of tabs near the top (Course, Participants, Grades...)
   and click **"More"** - a dropdown opens.
3. Click **"Embed an OMERO slide"**.

If you don't see it there, you either don't have editing rights in this
particular course, or it hasn't been enabled yet - ask whoever manages
your Moodle's plugins.

## Step 1: Load a slide

You'll see a form with:

- **Subject account** - a dropdown. Pick whichever matches the department
  or subject area your slide belongs to. Don't see one yet, or need a new
  one? Click **"Manage your OMERO connections"** next to the dropdown (or
  go directly to `local/omeroembed/mysubjects.php?courseid=<id>`) to add
  your own OMERO service-account credentials - this is entirely
  self-service, nobody needs to set this up for you.
- **Image ID** - the OMERO image ID of the slide you want. You'll already
  know this from having uploaded/found the image in OMERO yourself.
- **Dataset ID (optional)** - if this slide is part of a dataset you want
  students to be able to browse between (see "Let students browse..."
  below), enter its ID here too. Leave blank for a single standalone slide.
- **Let students browse other images in this dataset** - only relevant if
  you gave a Dataset ID. If checked, students see a thumbnail strip they
  can click through to see other images in the same dataset, alongside
  the one you picked. If unchecked, they only ever see the one image.
- **Layout** - three choices:
  - *Slide on the left, text on the right*
  - *Text on the left, slide on the right*
  - *Slide only, no write-up text* - just the slide, no accompanying text
    box at all.
- **Width / Height** - how big the embed will be. The **Width** default is
  already set to roughly match your Moodle theme's actual content column,
  so what you see while building the embed here looks the same once it's
  published on a real course page. You generally don't need to change
  this unless you know your theme is unusually wide or narrow.

Click **Load slide**. You'll see the real, live slide viewer appear - pan
and zoom it exactly like you would in OMERO itself, since it *is* OMERO,
just shown through this page.

## Step 2: Write your text and add view-links

(Skipped if you chose "Slide only".)

If you're used to preparing teaching material with a slide on one side and
your commentary on the other, with certain words linking to a specific
part of the slide, this is that same pattern - just without needing to
write any HTML or copy any URLs by hand.

1. Type your write-up in the text box next to the slide, as normal.
2. Pan and zoom the slide to a spot worth pointing out.
3. **Select the relevant word or phrase** in your text (e.g. select the
   word "enamel" in a sentence about enamel).
4. Click **Insert view link**. That text becomes a clickable link - later,
   when a student clicks it, the slide jumps to exactly the view you had
   set up.
5. Repeat for as many different views as you like, even on the same
   image - pan/zoom to a new spot, select different text, click **Insert
   view link** again.

**Changed your mind about the layout?** Switching between the three layout
options at any point is completely safe - nothing you've already written
gets lost, no matter how many times you switch back and forth.

## Step 3: Set the opening view (optional)

This is a separate thing from view-links. A view-link is "clicking this
word jumps the slide to here." The **opening view** is "when this embed
first loads on the page, before anyone clicks anything, this is what it
shows."

To set it: pan/zoom the slide to the position you want it to open on, then
click **Set as opening view**. Unlike a view-link, this doesn't insert
anything into your text and doesn't move the slide around when you click
it - it just quietly remembers that position for when you generate the
final embed.

If you skip this step, the embed just opens showing the whole slide at
its default zoom.

## Step 4: Generate and paste

1. Click **Generate embed HTML**.
2. Click **Copy to clipboard**.
3. Go to wherever you want the slide to appear - a Label, a Page, a Book
   chapter, anything with Moodle's normal text editor - and paste.
4. Save.

That's it. Students see the slide and your write-up exactly as you built
them. Clicking one of your view-links reloads *only* the slide, not the
whole page, so their place in your text is never lost.

## A few things worth knowing

- **Students never need their own OMERO account.** Everything runs through
  a shared subject account behind the scenes, gated entirely by their
  normal Moodle enrolment.
- **You can come back and load a different slide any time** by revisiting
  the tool and filling in the form again - it doesn't remember or lock in
  your previous choice.
- **If the slide shows "Image not found"** immediately after loading, it's
  usually a temporary hiccup that resolves itself on a retry - if it
  persists, let whoever manages the plugin's settings know.
