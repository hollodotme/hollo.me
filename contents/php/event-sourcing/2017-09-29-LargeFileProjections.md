## Use case

When I dove into event sourcing some years ago, I had a very challenging use-case for my first event-sourced project.
That time I was starting a new job after a sabbatical for a few month. This company needed a project management tool 
preferably "fulfilling all the unit's needs". One of these needs was a workflow for online shop frontend design-negotiation 
between the company's art directors (designer) and the customer's e-commerce (customer) team.

**A simplified version of this process is:**

1. Customer and designers work out a vision of a design and create a structured document / wishlist.  
2. Designer creates a first draft in some graphics app and produce a PDF to handover to the customer.
3. Customer reviews the design/PDF and adds remarks to that particular version of the design/PDF.
4. Designer reviews the comments on the design/PDF version and maybe adds own comments to that version.
5. Designer refines the design, produces a new PDF and uploads the new version  
   
   **_Repeating points 3 to 5 until the design is accepted._**  
   
7. Customer and designer agree on the final design by explicitly accepting a particular version.  
   They must not agree on the last version!

Obviously, in this case the content of the design PDFs is essential to understand comments from customer/designer and
the workout of the agreement, especially in a retrospective. The basic point was creating the ability to recap 
the whole refinement and decision process for both, customer and designer.

---

## Assumptions, discussion and decisions

I assumed that it is not needed to keep every version of a PDF available at the same time and that it is sufficient 
to get access to previous versions on demand.

I discussed about storing files in an event-sourced application in 
[this google group](https://groups.google.com/forum/#!topic/dddinphp/5DYL9T9vwmU) with a couple of people.
Please keep in mind that this discussion was about 3 years ago, I was not as open-minded as I am today and some parts may 
be offending from a current point of view.

Based on the use-case I decided to model an own aggregate for attachments, to keep track of its state (acceptance) 
and version.

I decided to see the actual file the customer/designer will see and the file system it is stored in to 
be a projection.

I also decided to store the binary data of the PDF version beside the actual event store table in another table containing 
a BLOB column. 






 
